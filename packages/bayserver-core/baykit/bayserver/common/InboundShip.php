<?php
namespace baykit\bayserver\common;



use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\docker\Port;
use baykit\bayserver\docker\Trouble;
use baykit\bayserver\HttpException;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\ship\Ship;
use baykit\bayserver\Sink;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\tour\TourHandler;
use baykit\bayserver\tour\TourStore;
use baykit\bayserver\util\ArrayUtil;
use baykit\bayserver\util\Counter;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\StringUtil;

class InboundShip extends Ship
{
    public static $errCounter = null;

    const MAX_TOURS = 128;

    public ?ProtocolHandler $protocolHandler = null;
    public ?Port $portDocker = null;
    public ?TourStore $tourStore = null;
    public bool $needEnd = false;
    public int $socketTimeoutSec = 0;
    public $activeTours = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function __toString()
    {
        if($this->protocolHandler != null)
            $protocol = $this->protocolHandler->protocol();
        else
            $protocol = "";

        return "agt#{$this->agentId} ship#{$this->shipId}/{$this->objectId}[{$protocol}]";
    }

    public function initInbound(Rudder $rd, int $agtId, Transporter $tp, Port $portDkr, ProtocolHandler $protoHnd) : void
    {
        $this->init($agtId, $rd, $tp);
        $this->portDocker = $portDkr;
        $this->socketTimeoutSec = $this->portDocker->timeoutSec >= 0 ? $this->portDocker->timeoutSec : BayServer::$harbor->socketTimeoutSec();
        $this->tourStore = TourStore::getStore($agtId);
        $this->setProtocolHandler($protoHnd);
    }

    ///////////////////////////////////////////////////////////////////////
    // Implements Reusable
    ///////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        parent::reset();

        if (count($this->activeTours) > 0)
            throw new Sink("%s There are some running tours", $this);

        $this->activeTours = [];

        $this->needEnd = false;
    }

    //////////////////////////////////////////////////////
    // Implements Ship
    //////////////////////////////////////////////////////

    public function notifyHandshakeDone(string $pcl): int
    {
        return NextSocketAction::CONTINUE;
    }

    public function notifyConnect(): int
    {
        throw new Sink();
    }

    public function notifyRead(string $buf): int
    {
        return $this->protocolHandler->bytesReceived($buf);
    }

    public function notifyEof(): int
    {
        BayLog::debug("%s EOF detected", $this);
        return NextSocketAction::CLOSE;
    }

    public function notifyError(\Exception $e): void
    {
        BayLog::debug($e, "%s Error notified", $this);
    }

    public function notifyProtocolError(ProtocolException $e): bool
    {
        BayLog::debug_e($e);
        return $this->tourHandler()->onProtocolError($e);
    }

    public function notifyClose(): void
    {
        BayLog::debug("%s notifyClose", $this);

        $this->abortTours();

        if(!empty($this->activeTours)) {
            // cannot close because there are some running tours
            BayLog::debug($this . " cannot end ship because there are some running tours (ignore)");
            $this->needEnd = true;
        }
        else {
            $this->endShip();
        }
    }

    public function checkTimeout(int $durationSec): bool
    {
        if($this->socketTimeoutSec <= 0)
            $timeout = false;
        else if($this->keeping)
            $timeout = $durationSec >= BayServer::$harbor->keepTimeoutSec();
        else
            $timeout = $durationSec >= $this->socketTimeoutSec;

        BayLog::debug("%s Check timeout: dur=%d, timeout=%b, keeping=%b limit=%d keeplim=%d",
            $this, $durationSec, $timeout, $this->keeping, $this->socketTimeoutSec, BayServer::$harbor->keepTimeoutSec());
        return $timeout;
    }

    //////////////////////////////////////////////////////
    // Other methods
    //////////////////////////////////////////////////////

    public function portDocker() : Port
    {
        return $this->portDocker;
    }

    public function setProtocolHandler(ProtocolHandler $hnd): void
    {
        $this->protocolHandler = $hnd;
        $this->protocolHandler->init($this);
        BayLog::debug("%s protocol handler is set", $this);
    }

    public function tourHandler(): TourHandler
    {
        return $this->protocolHandler->commandHandler;
    }

    public function getTour(int $turKey, bool $force=false, bool $rent=true) : ?Tour
    {
        $storeKey = $this->uniqKey($this->shipId, $turKey);
        $tur = $this->tourStore->get($storeKey);
        if ($tur === null && $rent) {
            $tur = $this->tourStore->rent($storeKey, $force);
            if($tur === null)
                return null;
            $tur->init($turKey, $this);
            $this->activeTours[] = $tur;
        }

        return $tur;
    }

    public function getErrorTour() : Tour
    {
        $turKey = InboundShip::$errCounter->next();
        $storeKey = $this->uniqKey($this->shipId, -$turKey);
        $tur = $this->tourStore->rent($storeKey,true);
        $tur->init(-$turKey, $this);
        $this->activeTours[] = $tur;
        return $tur;
    }


    public function sendHeaders(int $chkId, Tour $tur) : void {
        $this->checkShipId($chkId);

        foreach ($this->portDocker()->additionalHeaders() as $nv) {
            $tur->res->headers->add($nv[0], $nv[1]);
        }

        $this->tourHandler()->sendResHeaders($tur);
    }

    public function sendRedirect(int $chkId, Tour $tour, int $status, String $location) : void
    {
        $this->checkShipId($chkId);

        $hdr = $tour->res->headers;
        $hdr->status = $status;
        $hdr->set(Headers::LOCATION, $location);

        $body = "<H2>Document Moved.</H2><BR>" . "<A HREF=\""
                . $location . "\">" . $location . "</A>";

        $this->sendErrorContent($chkId, $tour, $body);
    }

    public function sendResContent(int $chkId, Tour $tur, string $bytes, int $ofs, int $len, ?callable $callback) : void
    {
        $this->checkShipId($chkId);

        $maxLen = $this->protocolHandler->maxResPacketDataSize();
        if($len > $maxLen) {
            $this->sendResContent(Ship::SHIP_ID_NOCHECK, $tur, $bytes, $ofs, $maxLen, null);
            $this->sendResContent(Ship::SHIP_ID_NOCHECK, $tur, $bytes, $ofs + $maxLen, $len - $maxLen, $callback);
        }
        else {
            try {
                $this->tourHandler()->sendResContent($tur, $bytes, $ofs, $len, $callback);
            }
            catch(IOException $e) {
                $tur->changeState(Tour::TOUR_ID_NOCHECK, Tour::STATE_ABORTED);
                throw $e;
            }
        }
    }

    public function sendEndTour(int $chkShipId, Tour $tur, callable $callback) : void
    {
        $this->checkShipId($chkShipId);

        BayLog::debug("%s sendEndTour: %s state=%s", $this, $tur, $tur->state);

        if(!$tur->isValid()) {
            throw new Sink("Tour is not valid");
        }
        $keepAlive = false;
        if ($tur->req->headers->getConnection() == Headers::CONNECTION_KEEP_ALIVE)
            $keepAlive = true;
        if($keepAlive) {
            $resConn = $tur->res->headers->getConnection();
            $keepAlive = ($resConn == Headers::CONNECTION_KEEP_ALIVE)
                || ($resConn == Headers::CONNECTION_UNKOWN);
            if ($keepAlive) {
                if ($tur->res->headers->contentLength() < 0)
                    $keepAlive = false;
            }
        }

        $this->tourHandler()->sendEndTour($tur, $keepAlive, $callback);;
    }

    public function sendError(int $chkId, Tour $tour, int $status, String $message, ?\Throwable $e) : void
    {
        $this->checkShipId($chkId);

        BayLog::debug("%s send error: status=%d, message=%s", $this, $status, $message, $e === null ? "" : $e->getMessage());
        if ($e !== null)
            BayLog::error_e($e);

        // Create body
        $str = HttpStatus::description($status);

        // print status
        $body = "<h1>{$status} {$str}</h1>\r\n";

        $tour->res->headers->status = $status;
        $this->sendErrorContent($chkId, $tour, $body);
    }

    protected function sendErrorContent(int $chkId, Tour $tur, string $content) : void
    {
        // Get charset
        $charset = $tur->res->charset();

        // Set content type
        if (StringUtil::isSet($charset)) {
            $tur->res->headers->setContentType("text/html; charset=" . $charset);
        }
        else {
            $tur->res->headers->setContentType("text/html");
        }

        $bytes = null;
        if (StringUtil::isSet($content)) {
            // Create writer
            if (StringUtil::isSet($charset)) {
                try {
                    $bytes = mb_convert_encoding($content, $charset, "ascii");
                }
                catch(\Error $e) {
                    BayLog::warn("Cannot convert string: %s", $e->getMessage());
                }
            }
            if ($bytes == null) {
                $bytes = $content;
            }
            $tur->res->headers->setContentLength(strlen($bytes));
        }
        $this->sendHeaders($chkId, $tur);

        if ($bytes !== null)
            $this->sendResContent($chkId, $tur, $bytes, 0, strlen($bytes), null);
    }

    public function endShip() : void
    {
        BayLog::debug("%s endShip", $this);
        $this->portDocker->returnProtocolHandler($this->agentId, $this->protocolHandler);
        $this->portDocker->returnShip($this);
    }

    public function abortTours() : void
    {
        $returnList = [];

        // Abort tours
        foreach ($this->activeTours as $tur) {
            if($tur->isValid()) {
                BayLog::debug("%s is valid, abort it: stat=%s", $tur, $tur->state);
                if($tur->req->abort()) {
                    $returnList[] = $tur;
                }
            }
        }

        foreach ($returnList as $tur) {
            $this->returnTour($tur);
        }
    }

    private static function uniqKey(int $sipId, int $turKey) : int
    {
        return ($sipId << 32) | $turKey;
    }

    public function returnTour(Tour $tur) : void
    {
        BayLog::debug("%s Return tour: %s", $this, $tur);
        if(!in_array($tur, $this->activeTours))
            throw new Sink("Tour is not in acive list: %s", $tur);

        $this->tourStore->Return($this->uniqKey($this->shipId, $tur->req->key));
        ArrayUtil::remove($tur, $this->activeTours);

        if($this->needEnd && count($this->activeTours) == 0) {
            $this->endShip();
        }
    }
    
    public static function initClass() {
        InboundShip::$errCounter = new Counter();
    }


}

InboundShip::initClass();