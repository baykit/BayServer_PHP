<?php
namespace baykit\bayserver\docker\cgi;

use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\Sink;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\Valve;
use baykit\bayserver\watercraft\Yacht;


class CGIStdErrYacht extends Yacht
{
    public $tour;
    public $tourId;
    public $handler;

    public function __construct()
    {
        $this->reset();
    }

    public function __toString() : string
    {
        return "CGIErrYat#{$this->yachtId}/{$this->objectId} tour={$this->tour} id={$this->tourId}";
    }

    ////////////////////////////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        $this->tourId = 0;
        $this->tour = null;
        $this->handler = null;
    }

    ////////////////////////////////////////////////////////////////////
    // Implements Yacht
    ////////////////////////////////////////////////////////////////////

    public function notifyRead(string $buf, ?array $adr): int
    {
        BayLog::debug("%s CGI StdErr %d bytesd", $this, strlen($buf));
        if(strlen($buf) > 0)
            BayLog::error("CGI Stderr: %s", $buf);

        $this->handler->access();
        return NextSocketAction::CONTINUE;
    }

    public function notifyEof(): int
    {
        BayLog::debug("%s CGI StdErr: EOF\\(^o^)/", $this);
        return NextSocketAction::CLOSE;
    }

    public function notifyClose(): void
    {
        BayLog::debug("%s CGI StdErr: notifyClose", $this);
        $this->handler->stdErrClosed();
    }

    public function checkTimeout(int $durationSec): bool
    {
        BayLog::debug("%s Check StdErr timeout: dur=%d", $this, $durationSec);
        return $this->handler->timedOut();
    }

    ////////////////////////////////////////////////////////////////////
    // Custom methods
    ////////////////////////////////////////////////////////////////////

    public function init(Tour $tur, CGIReqContentHandler $handler) : void
    {
        $this->initYacht();
        $this->tour = $tur;
        $this->tourId = $tur->tourId;
        $this->handler = $handler;
    }
}
