<?php
namespace baykit\bayserver\docker\cgi;

use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\common\ReadOnlyShip;
use baykit\bayserver\rudder\Rudder;


class CGIStdErrShip extends ReadOnlyShip
{
    private ?CGIReqContentHandler $handler;

    public function __toString() : string
    {
        return "agt#{$this->agentId} err_ship#{$this->shipId}/{$this->objectId}";
    }

    /////////////////////////////////////
    // Initialize methods
    /////////////////////////////////////
    public function initErrShip(Rudder $rd, int $agentId, CgiReqContentHandler $handler): void {
        parent::init($agentId, $rd, null);
        $this->handler = $handler;
    }

    /////////////////////////////////////
    // Implements Reusable
    /////////////////////////////////////

    public function reset(): void
    {
        parent::reset();
        $this->handler = null;
    }

    /////////////////////////////////////
    // Implements Ship
    /////////////////////////////////////

    public function notifyRead(string $buf): int
    {
        BayLog::debug("%s CGI StdErr %d bytes", $this, strlen($buf));
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

    public function notifyError(\Exception $e): void
    {
        BayLog::debug_e($e, "%s CGI StdErr: notifyError", $this);
    }
}
