<?php
namespace baykit\bayserver\docker\base;



use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\docker\Port;
use baykit\bayserver\docker\Trouble;
use baykit\bayserver\HttpException;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\Sink;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\tour\TourStore;
use baykit\bayserver\util\ArrayUtil;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\watercraft\Ship;

class InboundShip extends Ship
{
    public static $err_counter;

    const MAX_TOURS = 128;

    public $portDocker = null;
    public $tourStore = null;
    public $needEnd = null;
    public $socketTimeoutSec = null;
    public $activeTours = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function __toString()
    {
        $protocol = $this->protocol();
        if ($this->postman && $this->postman->secure())
            $protocol .= "s";
        return "{$this->agent} ship#{$this->shipId}/#{$this->objectId}[{$protocol}]";
    }

    public function initInbound($skt, $agt, $postman, $port, $protoHnd) : void
    {
        $this->init($skt, $agt, $postman);
        $this->portDocker = $port;
        $this->socketTimeoutSec = $this->portDocker->timeoutSec >= 0 ? $this->portDocker->timeoutSec : BayServer::$harbor->socketTimeoutSec;
        $this->tourStore = TourStore::getStore($agt->agentId);
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

    ///////////////////////////////////////////////////////////////////////
    // Other methods
    ///////////////////////////////////////////////////////////////////////

    public function portDocker() : Port
    {
        return $this->portDocker;
    }

    public function getTour(int $turKey, bool $force=false) : ?Tour
    {
        $storeKey = $this->uniqKey($this->shipId, $turKey);
        $tur = $this->tourStore->get($storeKey);
        if ($tur === null) {
            $tur = $this->tourStore->rent($storeKey, $force);
            if($tur === null)
                return null;
            $tur->init($turKey, $this);
            $this->activeTours[] = $tur;
        }

        if($tur->ship != $this)
            throw new Sink();

        $tur->checkTourId($tur->id());
        return $tur;
    }

    public function getErrorTour() : Tour
    {
        $turKey = $this->errCounter->next();
        $storeKey = $this->uniqKey($this->shipId, -$turKey);
        $tur = $this->tourStore->rent($storeKey,true);
        $tur.init(-$turKey, $this);
        $this->activeTours[] = $tur;
        return $tur;
    }


    public function sendHeaders(int $chkId, Tour $tur) : void {
        $this->checkShipId($chkId);

        if($tur->isZombie() || $tur->isAborted())
            // Don't send peer any data
            return;

        $handled = false;
        if(!$tur->errorHandling && $tur->res->headers->status >= 400) {
            $trb = BayServer::$harbor->trouble;
            if($trb !== null) {
                $cmd = $trb->find($tur->res->headers->status);
                if ($cmd !== null) {
                    $errTour = $this->getErrorTour();
                    $errTour->req->uri = $cmd->target;
                    $tur->req->headers->copyTo($errTour->req->headers);
                    $tur->res->headers->copyTo($errTour->res->headers);
                    $errTour->req->remotePort = $tur->req->remotePort;
                    $errTour->req->remoteAddress = $tur->req->remoteAddress;
                    $errTour->req->serverAddress = $tur->req->serverAddress;
                    $errTour->req->serverPort = $tur->req->serverPort;
                    $errTour->req->serverName = $tur->req->serverName;
                    $errTour->res->headerSent = $tur->res->headerSent;
                    $tur->changeState(Tour::TOUR_ID_NOCHECK, Tour::STATE_ZOMBIE);
                    switch ($cmd->method) {
                        case Trouble::GUIDE: {
                            $errTour->go();
                            break;
                        }

                        case Trouble::TEXT: {
                            $this->protocolHandler->sendResHeaders($errTour);
                            $data = $cmd->target->getBytes();
                            $errTour->res->sendContent(Tour::TOUR_ID_NOCHECK, $data, 0, strlen($data));
                            $errTour->res->endContent(Tour::TOUR_ID_NOCHECK);
                            break;
                        }

                        case Trouble::REROUTE: {
                            $errTour->res->sendHttpException(Tour::TOUR_ID_NOCHECK, HttpException::movedTemp($cmd->target));
                            break;
                        }
                    }
                    $handled = true;
                }
            }
        }
        if(!$handled) {
            foreach ($this->portDocker()->additionalHeaders() as $nv) {
                $tur->res->headers->add($nv[0], $nv[1]);
            }
            try {
                $this->protocolHandler->sendResHeaders($tur);
            }
            catch(IOException $e) {
                BayLog::debug($e, "%s abort: %s", $tur, $e);
                $tur->changeState(Tour::TOUR_ID_NOCHECK, Tour::STATE_ABORTED);
                throw $e;
            }
        }
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

        if($tur->isZombie() || $tur->isAborted()) {
            // Don't send peer any data. Do nothing
            BayLog::debug("%s Aborted or zombie tour. do nothing: %s state=%s", $this, $tur, $tur->state);
            $tur->changeState($chkId, TourState::ENDED);
            if($callback != null)
                $callback();
            return;
        }

        $maxLen = $this->protocolHandler->maxResPacketDataSize();
        if($len > $maxLen) {
            $this->sendResContent(Tour::TOUR_ID_NOCHECK, $tur, $bytes, $ofs, $maxLen, null);
            $this->sendResContent(Tour::TOUR_ID_NOCHECK, $tur, $bytes, $ofs + $maxLen, $len - $maxLen, $callback);
        }
        else {
            try {
                $this->protocolHandler->sendResContent($tur, $bytes, $ofs, $len, $callback);
            }
            catch(IOException $e) {
                $tur->changeState(Tour::TOUR_ID_NOCHECK, Tour::STATE_ABORTED);
                throw $e;
            }
        }
    }

    public function sendEndTour(int $chkShipId, int $chkTourId, Tour $tur, callable $callback) : void
    {
        $this->checkShipId($chkShipId);

        BayLog::debug("%s sendEndTour: %s state=%s", $this, $tur, $tur->state);

        if($tur->isZombie() || $tur->isAborted()) {
            // Don't send peer any data. Only return tour
            $callback();
        }
        else {
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

            //BayLog.trace("%s sendEndTour: set running false: %s id=%d", this, tur, chkTourId);
            $tur->changeState($chkTourId, Tour::STATE_ENDED);

            $this->protocolHandler->sendEndTour($tur, $keepAlive, $callback);;
        }
    }

    public function sendError(int $chekId, Tour $tour, int $status, String $message, ?\Throwable $e) : void
    {
        $this->checkShipId($chekId);

        BayLog::debug("%s send error: status=%d, message=%s ex=%s", $this, $status, $message, $e === null ? "" : $e->getMessage(), $e);
        if ($e !== null)
            BayLog::error($e);

        // Create body
        $str = HttpStatus::description($status);

        // print status
        $body = "<h1>{$status} {$str}</h1>\r\n";

        $tour->res->headers->status = $status;
        $this->sendErrorContent($chekId, $tour, $body);
    }

    protected function sendErrorContent(int $shipId, Tour $tur, string $content) : void
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
        $this->sendHeaders($shipId, $tur);

        if ($bytes !== null)
            $this->sendResContent($shipId, $tur, $bytes, 0, strlen($bytes), null);
    }

    public function endShip() : void
    {
        BayLog::debug("%s endShip", $this);
        $this->portDocker->returnProtocolHandler($this->agent, $this->protocolHandler);
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
}