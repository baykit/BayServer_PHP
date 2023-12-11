<?php
namespace baykit\bayserver\docker\warp;


use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\agent\transporter\DataListener;
use baykit\bayserver\BayLog;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\IOException;

class WarpDataListener implements DataListener
{
    public $ship;

    public function __construct(WarpShip $sip)
    {
        $this->ship = $sip;
    }

    public function __toString()
    {
        return strval($this->ship);
    }

    /////////////////////////////////////////
    // Implements DataListener
    /////////////////////////////////////////

    public function notifyConnect(): int
    {
        BayLog::debug("%s notifyConnect", $this);
        $this->ship->connected = true;
        foreach(array_values($this->ship->tourMap) as $pir) {
            $tur = $pir[1];
            $tur->checkTourId($pir[0]);
            WarpData::get($tur)->start();
        }
        return NextSocketAction::CONTINUE;
    }

    public function notifyRead(string $buf, ?array $adr): int
    {
        return $this->ship->protocolHandler->bytesReceived($buf);
    }

    public function notifyEof(): int
    {
        BayLog::debug("%s EOF detected", $this);

        if(count($this->ship->tourMap) == 0) {
            BayLog::debug("%s No warp tour. only close", $this);
            return NextSocketAction::CLOSE;
        }
        foreach(array_keys($this->ship->tourMap) as $warpId) {
            $pair = $this->ship->tourMap[$warpId];
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
                    $tur->res->endContent(Tour::TOUR_ID_NOCHECK);
                }
            }
            catch(IOException $e) {
                BayLog::debug_e($e);
            }
        }
        $this->ship->tourMap = [];

        return NextSocketAction::CLOSE;
    }

    public function notifyHandshakeDone(string $protocol): int
    {
        $this->ship->protocolHandler->verifyProtocol($protocol);

        //  Send pending packet
        $this->ship->agent->nonBlockingHandler->askToWrite($this->ship->ch);
        return NextSocketAction::CONTINUE;
    }

    public function notifyProtocolError(ProtocolException $e): bool
    {
        BayLog::error($e);
        $this->ship->notifyErrorToOwnerTour(HttpStatus::SERVICE_UNAVAILABLE, $e->getMessage());
        return true;
    }

    public function notifyClose(): void
    {
        BayLog::debug($this . " notifyClose");
        $this->ship->notifyErrorToOwnerTour(HttpStatus::SERVICE_UNAVAILABLE, $this . " server closed");
        $this->ship->endShip();
    }

    public function checkTimeout(int $durationSec): bool
    {
        if($this->ship->isTimeout($durationSec)) {
            $this->ship->notifyErrorToOwnerTour(HttpStatus::GATEWAY_TIMEOUT, $this . " server timeout");
            return true;
        }
        else
            return false;
    }
}