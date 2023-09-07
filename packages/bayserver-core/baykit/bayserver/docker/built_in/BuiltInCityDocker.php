<?php

namespace baykit\bayserver\docker\built_in;



use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\BayLog;
use baykit\bayserver\docker\base\DockerBase;
use baykit\bayserver\docker\City;
use baykit\bayserver\docker\Club;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\docker\file\FileDocker;
use baykit\bayserver\docker\Log;
use baykit\bayserver\docker\Permission;
use baykit\bayserver\docker\Town;
use baykit\bayserver\docker\Trouble;
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

    public $trouble;

    public $name;

    public function __toString()
    {
        return "City[" . $this->name . "]";
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
                $tur->req->charset = BayServer::$harbor->charset;
                $tur->res->setCharset(BayServer::$harbor->charset);
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
            $club->arrive($tur);
        }

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
                $relScriptName .= '/';
            $relScriptName .= $fname;
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
            $mi->scriptName = $townName . $relScriptName;
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
                $mi->redirectURI = $uri . "/";
                if($mi->queryString !== null)
                    $mi->redirectURI .= $mi->queryString;
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
                    $indexUri = $uri . $t->welcome;
                    $relUri = $rel . $t->welcome;
                    $indexLocation = $t->location . DIRECTORY_SEPARATOR . $relUri;
                    if(is_file($indexLocation)) {
                        if ($mi->queryString !== null)
                            $indexUri .= "?" . $mi->queryString;
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