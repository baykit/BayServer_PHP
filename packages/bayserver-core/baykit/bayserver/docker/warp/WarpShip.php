<?php
namespace baykit\bayserver\docker\warp;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\transporter\Transporter;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\Sink;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\IOException;
use baykit\bayserver\watercraft\Ship;

class WarpShip extends Ship
{
    public $docker;
    public $tourMap = [];

    public $connected;
    public $socketTimeoutSec;
    public $cmdBuf = [];


    public function __toString() : string
    {
        return $this->agent . " wsip#" . $this->shipId . "/" . $this->objectId . "[" . $this->protocol() . "]";
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
    }




    /////////////////////////////////////////////////
    // Other methods
    /////////////////////////////////////////////////

    public function initWarp (
            $ch,
            GrandAgent $agent,
            Transporter $tp,
            WarpDocker $dkr,
            ProtocolHandler $protoHandler) : void
    {
        parent::init($ch, $agent, $tp);
        $this->docker = $dkr;
        $this->socketTimeoutSec = $this->docker->timeoutSec >= 0 ? $this->docker->timeoutSec : BayServer::$harbor->socketTimeoutSec;
        $this->setProtocolHandler($protoHandler);
    }

    public function warpHandler() : WarpHandler
    {
        return $this->protocolHandler;
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
        $wHnd->postWarpHeaders($tur);

        if($this->connected) {
            BayLog::debug("%s is already connected. Start warp tour:%s", $wdat, $tur);
            $wdat->start();
        }
    }

    public function endWarpTour(Tour $tur) : void
    {
        $wdat = WarpData::get($tur);
        BayLog::debug("%s end: started=%b ended=%b", $tur, $wdat->started, $wdat->ended);
        if(!array_key_exists($wdat->warpId, $this->tourMap))
            throw new Sink("%s WarpId not in tourMap: %d", $tur, $wdat->warpId);
        unset($this->tourMap[$wdat->warpId]);
        $this->docker->keepShip($this);
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
                $tur->res->endContent(Tour::TOUR_ID_NOCHECK);
            }
        }

        $this->tourMap = [];
    }

    public function endShip() : void
    {
        $this->docker->returnProtocolHandler($this->agent, $this->protocolHandler);
        $this->docker->returnShip($this);
    }

    public function abort(int $checkId) : void
    {
        $this->checkShipId($checkId);
        $this->postman->abort();
    }

    public function isTimeout(int $durationSec) : bool
    {
        $timeout = true;
        if($this->keeping) {
            // warp connection never timeout in keeping
            $this->timeout = false;
        }
        elseif ($this->socketTimeoutSec <= 0)
            $timeout = false;
        else
            $timeout = $durationSec >= $this->socketTimeoutSec;

        BayLog::debug("%s Warp check timeout: dur=%d, timeout=%s, keeping=%s limit=%d",
            $this, $durationSec, $timeout, $this->keeping, $this->socketTimeoutSec);
        return $timeout;
    }

    public function post($cmd, $listener=null) : void
    {
        if(!$this->connected) {
            $this->cmdBuf[] = [$cmd, $listener];
        }
        else if ($cmd == null) {
            $listener();
        }
        else {
            $this->protocolHandler->commandPacker->post($this, $cmd, $listener);
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
                $this->protocolHandler->commandPacker->post($this, $cmd, $lis);
        }
        $this->cmdBuf = [];
    }

}