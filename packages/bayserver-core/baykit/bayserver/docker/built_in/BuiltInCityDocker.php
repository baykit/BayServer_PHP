<?php

namespace baykit\bayserver\docker\built_in;



use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\multiplexer\PlainTransporter;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\BayLog;
use baykit\bayserver\common\Barges;
use baykit\bayserver\common\RudderState;
use baykit\bayserver\common\RudderStateStore;
use baykit\bayserver\docker\Barge;
use baykit\bayserver\docker\base\DockerBase;
use baykit\bayserver\docker\City;
use baykit\bayserver\docker\Club;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\docker\file\FileDocker;
use baykit\bayserver\docker\Log;
use baykit\bayserver\docker\Permission;
use baykit\bayserver\docker\Town;
use baykit\bayserver\docker\Trouble;
use baykit\bayserver\tour\ReqContentHandler;
use baykit\bayserver\HttpException;
use baykit\bayserver\Symbol;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\URLDecoder;


class  ClubMatchInfo
{
    public $club;
    public $scriptName;
    public $pathInfo;
}

class MatchInfo
{
    public $town;
    public $clubMatch;
    public $queryString;
    public $redirectURI;
    public $rewrittenURI;
}

class BuiltInCityDocker extends DockerBase implements  City  {

    public $towns = [];
    public $defaultTown;

    public $clubs = [];
    public $defaultClub;

    public $logs = [];
    public $permissions = [];
    /** Barge dockers configured under this city */
    public Barges $barges;

    public $trouble;

    public $name;

    public function __construct()
    {
        $this->barges = new Barges();
    }

    public function __toString()
    {
        return "City[". $this->name. "]";
    }

    //////////////////////////////////////////////////////
    // Implements Docker
    //////////////////////////////////////////////////////

    public function init($elm, $parent) : void
    {
        parent::init($elm, $parent);

        $this->name = $elm->arg;
        uasort($this->towns, function ($d1, $d2) {
            return strlen($d2->name) - strlen($d1->name);
        });

        foreach ($this->towns as $t) {
            BayLog::debug(BayMessage::get(Symbol::MSG_SETTING_UP_TOWN, $t->name, $t->location));
        }

        $this->defaultTown = new BuiltInTownDocker();
        $this->defaultClub = new FileDocker();
    }

    //////////////////////////////////////////////////////
    // Implements DockerBase
    //////////////////////////////////////////////////////
    public function initDocker(Docker $dkr): bool
    {
        if ($dkr instanceof Town)
            $this->towns[] = $dkr;
        elseif ($dkr instanceof Club)
            $this->clubs[] = $dkr;
        elseif ($dkr instanceof Log)
            $this->logs[] = $dkr;
        elseif ($dkr instanceof Permission)
            $this->permissions[] = $dkr;
        elseif ($dkr instanceof Trouble)
            $this->trouble = $dkr;
        elseif ($dkr instanceof Barge)
            $this->barges->add($dkr);
        else
            return false;
        return true;
    }


    //////////////////////////////////////////////////////
    // Implements City
    //////////////////////////////////////////////////////

    public function name(): string
    {
        return $this->name;
    }

    public function clubs(): array
    {
        return $this->clubs;
    }

    public function towns(): array
    {
        return $this->towns;
    }

    public function enter(Tour $tur) : void
    {
        BayLog::debug("%s City[%s] Request URI: %s", $tur, $this->name, $tur->req->uri);

        $tur->city = $this;

        foreach ($this->permissions as $p) {
            $p->checkAdmitted($tur);
        }

        $mInfo = $this->getTownAndClub($tur->req->uri);
        if($mInfo === null) {
            throw new HttpException(HttpStatus::NOT_FOUND, $tur->req->uri);
        }

        $mInfo->town->checkAdmitted($tur);

        if($mInfo->redirectURI !== null) {
            throw HttpException::movedTemp($mInfo->redirectURI);
        }
        else {
            BayLog::debug("%s Town[%s] Club[%s]", $tur, $mInfo->town->name, $mInfo->clubMatch->club);
            $tur->req->queryString = $mInfo->queryString;
            $tur->req->scriptName = $mInfo->clubMatch->scriptName;

            if($mInfo->clubMatch->club->charset !== null) {
                $tur->req->charset = $mInfo->clubMatch->club->charset;
                $tur->res->setCharset($mInfo->clubMatch->club->charset);
            }
            else {
                $tur->req->charset = BayServer::$harbor->charset();
                $tur->res->setCharset(BayServer::$harbor->charset());
            }

            $tur->req->pathInfo = $mInfo->clubMatch->pathInfo;
            if($tur->req->pathInfo !== null && $mInfo->clubMatch->club->decodePathInfo) {
                $tur->req->pathInfo  = URLDecoder::decode($tur->req->pathInfo, $tur->req->charset);
            }
            if($mInfo->rewrittenURI !== null) {
                $tur->req->rewrittenURI = $mInfo->rewrittenURI;  // URI is rewritten
            }

            $club = $mInfo->clubMatch->club;
            $tur->town = $mInfo->town;
            $tur->club = $club;

            // Barge Docker fast-path: see if this request URI matches any
            // configured barge. enter
            //
            $barge = $this->findBargeForTour($tur, $mInfo->town);
            if ($barge !== null) {
                [$cgo, $waitRd] = $barge->getCargo($tur);
                if ($waitRd !== null) {
                    // Cargo is currently loading; subscribe a waiter ship.
                    $agt = GrandAgent::get($tur->ship->agentId);
                    $waitShip = new WaitCargoShip();
                    $tp = new PlainTransporter($agt->spiderMultiplexer, $waitShip, true, 8192, false);
                    $waitShip->initWaiter($waitRd, $tp, $tur, $cgo, $club);

                    $st = RudderStateStore::getStore($agt->agentId)->rent();
                    $st->init($waitRd, $tp);
                    $agt->spiderMultiplexer->addRudderState($waitRd, $st);
                    $agt->spiderMultiplexer->reqRead($waitRd);
                    return;
                }
                else if ($cgo !== null && $cgo->onBarge()) {
                    // Cargo is loaded -- serve from memory without
                    // bouncing through the club's arrive().
                    $tur->req->setContentHandler(new CargoFastPathHandler($cgo));
                    return;
                }
                else if ($cgo !== null) {
                    // First requester loads the file; record the cargo on
                    // the tour so TourRes.sendResHeaders / sendResContent
                    // / endResContent populate it as we serve.
                    $tur->cargo = $cgo;
                }
            }

            $club->arrive($tur);
        }

    }

    /**
     * Resolve which Barge (if any) applies to this tour. Looks up the
     * tour's town, then the city's own barges, then the harbor-level
     * fallback (mirrors upstream's BuiltInCityDocker.findBarge).
     */
    private function findBargeForTour(Tour $tur, Town $twn) : ?Barge
    {
        $b = $twn->findBarge($tur->req->uri);
        if ($b === null) {
            $b = $this->barges->findBarge($tur->req->uri);
            if ($b === null) {
                $b = BayServer::$harbor->findBarge($tur->req->uri);
            }
        }
        return $b;
    }

    /**
     * Cached is_file() for welcome-file probes. Avoids one stat per
     * request when the request URI ends in "/".
     * TTL keeps the cache fresh enough for new files to be picked up
     * within ~10 s of being added to docroot.
     */
    private static array $welcomeExistsCache = [];
    private const WELCOME_EXISTS_TTL = 10;

    private static function welcomeFileExists(string $path) : bool
    {
        $now = time();
        if (isset(self::$welcomeExistsCache[$path])) {
            [$exists, $expireAt] = self::$welcomeExistsCache[$path];
            if ($now <= $expireAt) {
                return $exists;
            }
        }
        $exists = is_file($path);
        self::$welcomeExistsCache[$path] = [$exists, $now + self::WELCOME_EXISTS_TTL];
        return $exists;
    }

    public function log(Tour $tur) : void
    {
        foreach ($this->logs as $d) {
            try {
                $d->log($tur);
            } catch (\Exception $e) {
                BayLog::error_e($e);
            }
        }
    }


    //////////////////////////////////////////////////////
    // Private methods
    //////////////////////////////////////////////////////

    private function clubMaches(array $clubList, string $relUri, string $townName) : ?ClubMatchInfo {

        $mi = new ClubMatchInfo();
        $anyd = null;

        foreach ($clubList as $d) {
            if ($d->fileName == "*" && $d->extension === null) {
                // Ignore any match club
                $anyd = $d;
                break;
            }
        }

        // search for club
        $relScriptName = "";

        foreach (explode("/", $relUri) as $fname) {
            if($relScriptName != "")
                $relScriptName.= '/';
            $relScriptName.= $fname;
            $breadLoop = false;
            foreach ($clubList as $d) {
                if($d == $anyd) {
                    // Ignore any match club
                    continue;
                }

                if ($d->matches($fname)) {
                    $mi->club = $d;
                    $breadLoop = true;
                    break;
                }
            }

            if($breadLoop)
                break;
        }

        if ($mi->club === null && $anyd !== null) {
            $mi->club = $anyd;
        }

        if ($mi->club === null)
            return null;

        if ($townName == "/" &&  $relScriptName == "") {
            $mi->scriptName = "/";
            $mi->pathInfo = null;
        }
        else {
            $mi->scriptName = $townName. $relScriptName;
            if (strlen($relScriptName) == strlen($relUri))
                $mi->pathInfo = null;
            else
                $mi->pathInfo = substr($relUri, strlen($relScriptName));
        }

        return $mi;
    }


    /**
     * Determine club from request URI
     */
    private function getTownAndClub(string $reqUri) : ?MatchInfo
    {
        $mi = new MatchInfo();

        $uri = $reqUri;
        $pos = strpos($uri, '?');
        if($pos !== false) {
            $mi->queryString = substr($uri, $pos + 1);
            $uri = substr($uri, 0, $pos);
        }

        foreach ($this->towns as $t) {
            $m = $t->matches($uri);
            if ($m == Town::MATCH_TYPE_NOT_MATCHED)
                continue;

            // town matched
            $mi->town = $t;
            if ($m == Town::MATCH_TYPE_CLOSE) {
                $mi->redirectURI = $uri. "/";
                if($mi->queryString !== null)
                    $mi->redirectURI.= $mi->queryString;
                return $mi;
            }

            $orgUri = $uri;
            $uri = $t->reroute($uri);
            if(!$uri == $orgUri)
                $mi->rewrittenURI = $uri;

            $rel = substr($uri, strlen($t->name));

            $mi->clubMatch = $this->clubMaches($t->clubs, $rel, $t->name);

            if($mi->clubMatch === null) {
                $mi->clubMatch = $this->clubMaches($this->clubs, $rel, $t->name);
            }

            if ($mi->clubMatch === null) {
                // check index file
                if(StringUtil::endsWith($uri, "/") && !StringUtil::isEmpty($t->welcome)) {
                    $indexUri = $uri. $t->welcome;
                    $relUri = $rel. $t->welcome;
                    $indexLocation = $t->location. DIRECTORY_SEPARATOR. $relUri;
                    if(self::welcomeFileExists($indexLocation)) {
                        if ($mi->queryString !== null)
                            $indexUri.= "?". $mi->queryString;
                        $m2 = $this->getTownAndClub($indexUri);
                        if ($m2 !== null) {
                            // matched
                            $m2->rewrittenURI = $indexUri;
                            return $m2;
                        }
                    }
                }

                // default club matches
                $mi->clubMatch = new ClubMatchInfo();
                $mi->clubMatch->club = $this->defaultClub;
                $mi->clubMatch->scriptName = null;
                $mi->clubMatch->pathInfo = null;
            }

            return $mi;
        }

        return null;
    }

}