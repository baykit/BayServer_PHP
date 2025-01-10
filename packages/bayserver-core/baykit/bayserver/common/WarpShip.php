<?php
namespace baykit\bayserver\common;

use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\docker\Warp;
use baykit\bayserver\docker\warp\WarpDocker;
use baykit\bayserver\protocol\Command;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\ship\Ship;
use baykit\bayserver\Sink;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\IOException;

class WarpShip extends Ship
{
    public Warp $docker;
    public $tourMap = [];

    public ?ProtocolHandler $protocolHandler = null;
    public bool $connected = false;
    public int $socketTimeoutSec = 0;
    public $cmdBuf = [];


    public function __toString() : string
    {
        return "agt#" . $this->agentId . " wsip#" . $this->shipId . "/" . $this->objectId .
            ($this->protocolHandler != null ? ("[" . $this->protocolHandler->protocol() . "]") : "");
    }

    public function initWarp (
        Rudder          $rd,
        int             $agtId,
        Transporter     $tp,
        Warp            $dkr,
        ProtocolHandler $protoHandler) : void
    {
        parent::init($agtId, $rd, $tp);
        $this->docker = $dkr;
        $this->socketTimeoutSec = $this->docker->timeoutSec >= 0 ? $this->docker->timeoutSec : BayServer::$harbor->socketTimeoutSec();
        $this->protocolHandler = $protoHandler;
        $this->protocolHandler->init($this);
    }

    /////////////////////////////////////////////////
    // Implements Reusable
    /////////////////////////////////////////////////

    public function reset(): void
    {
        parent::reset();
        if(count($this->tourMap) != 0)
            BayLog::error("BUG: Some tours is active: %s", $this->tourMap);
        $this->connected = false;
        $this->tourMap = [];
        $this->cmdBuf = [];
        $this->protocolHandler = null;
    }

    /////////////////////////////////////////////////
    // Implements Ship
    /////////////////////////////////////////////////

    public function notifyHandshakeDone(string $pcl): int
    {
        $this->protocolHandler->verifyProtocol($pcl);
        return NextSocketAction::CONTINUE;
    }

    public function notifyConnect(): int
    {
        BayLog::debug("%s notifyConnect", $this);
        $this->connected = true;
        foreach($this->tourMap as $pir) {
            $tur = $pir[1];
            $tur->checkTourId($pir[0]);
            WarpData::get($tur)->start();
        }
        return NextSocketAction::CONTINUE;
    }

    public function notifyRead(string $buf): int
    {
        return $this->protocolHandler->bytesReceived($buf);
    }

    public function notifyEof(): int
    {
        BayLog::debug("%s EOF detected", $this);

        if(empty($this->tourMap)) {
            BayLog::debug("%s No warp tour. only close", $this);
            return NextSocketAction::CLOSE;
        }
        foreach($this->tourMap as $warpId => $pair) {
            $tur = $pair[1];
            $tur->checkTourId($pair[0]);

            try {
                if (!$tur->res->headerSent) {
                    BayLog::debug("%s Send ServiceUnavailable: tur=%s", $this, $tur);
                    $tur->res->sendError(Tour::TOUR_ID_NOCHECK, HttpStatus::SERVICE_UNAVAILABLE, "Server closed on reading headers");
                }
                else {
                    // NOT treat EOF as Error
                    BayLog::debug("%s EOF is not an error: tur=%s", $this, $tur);
                    $tur->res->endResContent(Tour::TOUR_ID_NOCHECK);
                }
            }
            catch(IOException $e) {
                BayLog::debug_e($e);
            }
        }
        $this->tourMap = [];

        return NextSocketAction::CLOSE;

    }

    public function notifyError(\Exception $e): void
    {
        BayLog::debug_e($e, "%s Error notified", $this);
    }

    public function notifyProtocolError(ProtocolException $e): bool
    {
        BayLog::error($e);
        $this->notifyErrorToOwnerTour(HttpStatus::SERVICE_UNAVAILABLE, $e->getMessage());
        return true;
    }

    public function notifyClose(): void
    {
        BayLog::debug($this . " notifyClose");
        $this->notifyErrorToOwnerTour(HttpStatus::SERVICE_UNAVAILABLE, $this . " server closed");
        $this->endShip();
    }

    public function checkTimeout(int $durationSec): bool
    {
        if($this->isTimeout($durationSec)) {
            $this->notifyErrorToOwnerTour(HttpStatus::GATEWAY_TIMEOUT, $this . " server timeout");
            return true;
        }
        else
            return false;
    }


    /////////////////////////////////////////////////
    // Other methods
    /////////////////////////////////////////////////

    public function warpHandler() : WarpHandler
    {
        return $this->protocolHandler->commandHandler;
    }

    public function startWarpTour(Tour $tur) : void
    {
        $wHnd = $this->warpHandler();
        $warpId = $wHnd->nextWarpId();
        $wdat = $wHnd->newWarpData($warpId);
        BayLog::debug("%s new warp tour related to %s", $wdat, $tur);
        $tur->req->setContentHandler($wdat);

        BayLog::debug("%s start: warpId=%d", $wdat, $warpId);
        if(array_key_exists($warpId, $this->tourMap))
            throw new Sink("warpId exists");

        $this->tourMap[$warpId] = [$tur->id(), $tur];
        $wHnd->sendReqHeaders($tur);

        if($this->connected) {
            BayLog::debug("%s is already connected. Start warp tour:%s", $wdat, $tur);
            $wdat->start();
        }
    }

    public function endWarpTour(Tour $tur, bool $keep) : void
    {
        $wdat = WarpData::get($tur);
        BayLog::debug("%s end: started=%b ended=%b", $tur, $wdat->started, $wdat->ended);
        if(!array_key_exists($wdat->warpId, $this->tourMap))
            throw new Sink("%s WarpId not in tourMap: %d", $tur, $wdat->warpId);
        unset($this->tourMap[$wdat->warpId]);
        if($keep) {
            $this->docker->keep($this);
        }
    }

    public function notifyServiceUnavailable(string $msg) : void
    {
        $this->notifyErrorToOwnerTour(HttpStatus::SERVICE_UNAVAILABLE, $msg);
    }

    public function getTour(int $warpId, bool $must = true) : ?Tour
    {
        $pair = array_key_exists($warpId, $this->tourMap) ? $this->tourMap[$warpId] : null;
        if($pair != null) {
            $tur = $pair[1];
            $tur->checkTourId($pair[0]);
            if (!WarpData::get($tur)->ended) {
                return $tur;
            }
        }

        if($must)
            throw new Sink("%s warp tours not found: id=%d", $this, $warpId);
        else
            return null;
    }

    /////////////////////////////////////////////////
    // Private methods
    /////////////////////////////////////////////////

    public function notifyErrorToOwnerTour(int $status, string $msg) : void
    {
        foreach (array_keys($this->tourMap) as $warpId)  {
            $tur = $this->getTour($warpId);
            BayLog::debug("%s send error to owner: %s running=%b", $this, $tur, $tur->isRunning());
            if ($tur->isRunning()) {
                try {
                    $tur->res->sendError(Tour::TOUR_ID_NOCHECK, $status, $msg);
                }
                catch (IOException $e) {
                    BayLog::error_e($e);
                }
            }
            else {
                $tur->res->endResContent(Tour::TOUR_ID_NOCHECK);
            }
        }

        $this->tourMap = [];
    }

    public function endShip() : void
    {
        $this->docker->onEndShip($this);
    }

    public function abort(int $checkId) : void
    {
        $this->checkShipId($checkId);
        $this->transporter->reqClose($this->rudder);
    }

    public function isTimeout(int $durationSec) : bool
    {
        $timeout = true;
        if($this->keeping) {
            // warp connection never timeout in keeping
            $timeout = false;
        }
        elseif ($this->socketTimeoutSec <= 0)
            $timeout = false;
        else
            $timeout = $durationSec >= $this->socketTimeoutSec;

        BayLog::debug("%s Warp check timeout: dur=%d, timeout=%s, keeping=%s limit=%d",
            $this, $durationSec, $timeout, $this->keeping, $this->socketTimeoutSec);
        return $timeout;
    }

    public function post(?Command $cmd, ?callable $callback=null) : void
    {
        if(!$this->connected) {
            $this->cmdBuf[] = [$cmd, $callback];
        }
        else if ($cmd == null) {
            $callback();
        }
        else {
            $this->protocolHandler->post($cmd, $callback);
        }
    }

    public function flush() : void
    {
        foreach($this->cmdBuf as $cmd_and_lis) {
            $cmd = $cmd_and_lis[0];
            $lis = $cmd_and_lis[1];
            if($cmd == null)
                $lis();
            else
                $this->protocolHandler->post($cmd, $lis);
        }
        $this->cmdBuf = [];
    }
}