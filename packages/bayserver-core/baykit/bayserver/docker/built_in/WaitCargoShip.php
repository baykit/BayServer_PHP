<?php

namespace baykit\bayserver\docker\built_in;

use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\common\ReadOnlyShip;
use baykit\bayserver\common\Transporter;
use baykit\bayserver\docker\Barge_Cargo;
use baykit\bayserver\docker\Club;
use baykit\bayserver\HttpException;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\Sink;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\HttpStatus;

/**
 * Ship that waits on a one-byte pipe wake-up from MemBargeDocker.MemCargo.
 * When endSave() fires, the SpiderMultiplexer sees the pipe as readable
 * and invokes notifyRead() on this ship; we then flush the cargo bytes
 * back to the original tour. builtin.WaitCargoShip.
 */
class WaitCargoShip extends ReadOnlyShip
{
    private ?Barge_Cargo $cargo = null;
    private ?Club $club = null;
    private ?Tour $tour = null;
    private int $tourId = 0;

    /**
     * upstream has init(Rudder,Transporter,Tour,Cargo,Club) as an overload of
     * Ship.init(int,Rudder,Transporter). PHP can't overload so we rename
     * the cargo-waiter initializer to keep the parent signature intact.
     */
    public function initWaiter(Rudder $rd, Transporter $tp, Tour $tur, Barge_Cargo $cgo, Club $clb) : void
    {
        parent::init($tur->ship->agentId, $rd, $tp);
        $this->tour = $tur;
        $this->tourId = $tur->tourId;
        $this->cargo = $cgo;
        $this->club = $clb;
    }

    public function __toString() : string
    {
        return "agt#{$this->agentId} wait_cargo#{$this->shipId}/{$this->objectId}";
    }

    public function reset() : void
    {
        parent::reset();
        $this->tourId = 0;
        $this->tour = null;
        $this->cargo = null;
        $this->club = null;
    }

    public function notifyRead(string $buf): int
    {
        BayLog::debug("%s cargo load completed", $this->tour);

        try {
            if ($this->cargo->exceeded()) {
                BayLog::debug("%s cargo exceeded", $this->tour);
                $this->club->arrive($this->tour);
            }
            else {
                $this->tour->res->setConsumeListener(function ($len, $resume) {});
                $this->sendCargoOnBoard();
            }
        }
        catch (HttpException $e) {
            try {
                $this->tour->res->sendError(Tour::TOUR_ID_NOCHECK, $e->status, $e->getMessage());
            }
            catch (\Throwable $ex) {
                $this->notifyError($ex);
                return NextSocketAction::CLOSE;
            }
        }

        $this->cargo->releaseRudder($this->rudder);

        return NextSocketAction::CONTINUE;
    }

    public function notifyError(\Throwable $e): void
    {
        BayLog::debug_e($e, "%s Error notified", $this->tour);
        try {
            $this->tour->res->sendError($this->tourId, HttpStatus::INTERNAL_SERVER_ERROR, null, $e);
        }
        catch (\Throwable $ex) {
            BayLog::debug_e($ex);
        }
    }

    public function notifyEof(): int
    {
        throw new Sink();
    }

    public function notifyClose(): void
    {
    }

    public function checkTimeout(int $durationSec): bool
    {
        return false;
    }

    private function sendCargoOnBoard() : void
    {
        $this->tour->res->setConsumeListener(function ($len, $resume) {});
        $this->cargo->headers()->copyTo($this->tour->res->headers);
        try {
            $this->tour->res->sendResHeaders(Tour::TOUR_ID_NOCHECK);
            $this->tour->res->sendResContent(Tour::TOUR_ID_NOCHECK, $this->cargo->content(), 0, $this->cargo->length());
            $this->tour->res->endResContent(Tour::TOUR_ID_NOCHECK);
        }
        catch (\Throwable $e) {
            BayLog::error_e($e);
            throw new HttpException(HttpStatus::INTERNAL_SERVER_ERROR, $this->cargo->path());
        }
    }
}
