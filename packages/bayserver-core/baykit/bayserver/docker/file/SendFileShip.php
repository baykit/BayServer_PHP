<?php
namespace baykit\bayserver\docker\file;



use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\common\ReadOnlyShip;
use baykit\bayserver\common\Transporter;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\IOException;

class SendFileShip extends ReadOnlyShip
{
    private int $fileWroteLen = 0;

    private ?Tour $tour = null;
    private int $tourId = 0;

    public function initSendFile(Rudder $rd, Transporter $tp, Tour $tur): void
    {
        parent::init($tur->ship->agentId, $rd, $tp);
        $this->tour = $tur;
        $this->tourId = $tur->tourId;
    }

    public function __toString(): string
    {
        return "agt#{$this->agentId} send_file#{$this->shipId}/{$this->objectId}";
    }

    /////////////////////////////////////
    // Implements Reusable
    /////////////////////////////////////
    ///
    public function reset(): void {
        parent::reset();
        $this->fileWroteLen = 0;
        $this->tour = null;
        $this->tourId = 0;
    }

    /////////////////////////////////////
    // Implements Ship
    /////////////////////////////////////
    ///
    public function notifyRead(string $buf): int
    {
        $this->fileWroteLen += strlen($buf);
        BayLog::debug("%s read file %d bytes: total=%d", $this, strlen($buf), $this->fileWroteLen);

        try {
            $available = $this->tour->res->sendResContent($this->tourId, $buf, 0, strlen($buf));

            if($available) {
                return NextSocketAction::CONTINUE;
            }
            else {
                return NextSocketAction::SUSPEND;
            }
        }
        catch(IOException $e) {
            $this->notifyError($e);
            return NextSocketAction::CLOSE;
        }
    }

    public function notifyEof(): int
    {
        BayLog::debug("%s EOF", $this);
        try {
            $this->tour->res->endResContent($this->tourId);
        }
        catch(IOException $e) {
            BayLog::debug_e($e);
        }

        return NextSocketAction::CLOSE;
    }

    public function notifyClose(): void
    {
    }

    public function notifyError(\Exception $e): void
    {
        BayLog::debug_e($e, "%s Error notified", $this);
        try {
            $this->tour->res->sendError($this->tourId, HttpStatus::INTERNAL_SERVER_ERROR, null, $e);
        }
        catch(IOException $ex) {
            BayLog::debug_e($ex);
        }
    }

    public function checkTimeout(int $durationSec): bool
    {
        return false;
    }
}