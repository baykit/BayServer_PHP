<?php
namespace baykit\bayserver\docker\base;



use baykit\bayserver\agent\transporter\DataListener;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\Sink;


class InboundDataListener implements DataListener
{
    public $ship;

    public function __construct($sip)
    {
        $this->ship = $sip;
    }

    public function __toString()
    {
        return strval($this->ship);
    }

    ///////////////////////////////////////////////////////////////////////
    // Implements DataListener
    ///////////////////////////////////////////////////////////////////////

    public function notifyHandshakeDone(string $protocol): int
    {
        return NextSocketAction::CONTINUE;
    }

    public function notifyConnect(): int
    {
        throw new Sink("Illegal connect call");
    }

    public function notifyRead(string $buf, ?array $adr): int
    {
        BayLog::trace("%s notify_read", $this);
        return $this->ship->protocolHandler->bytesReceived($buf);
    }

    public function notifyEof(): int
    {
        BayLog::debug("%s notify_eof", $this);
        return NextSocketAction::CLOSE;
    }

    public function notifyProtocolError(ProtocolException $e): bool
    {
        BayLog::trace("%s notify_protocol_error", $this);
        if (BayLog::isDebugMode())
            BayLog::error_e($e);

        return $this->ship->protoclHandler->sendReqProtocolError($e);
    }

    public function notifyClose(): void
    {
        BayLog::debug("%s notifyClose", $this);

        $this->ship->abortTours();

        if(!count($this->ship->activeTours) == 0) {
            // cannot close because there are some running tours
            BayLog::debug($this . " cannot end ship because there are some running tours (ignore)");
            $this->needEnd = true;
        }
        else {
            $this->ship->endShip();
        }
    }

    public function checkTimeout(int $durationSec): bool
    {
        if($this->ship->socketTimeoutSec <= 0)
            $timeout = false;
        elseif($this->ship->keeping)
            $timeout = $durationSec >= BayServer::$harbor->keepTimeoutSec;
        else
            $timeout = $durationSec >= $this->ship->socketTimeoutSec;

        BayLog::debug("%s Check timeout: dur=%d, timeout=%b, keeping=%b limit=%d keeplim=%d",
            $this, $durationSec, $timeout, $this->ship->keeping, $this->ship->socketTimeoutSec, BayServer::$harbor->keepTimeoutSec);
        return $timeout;
    }

}