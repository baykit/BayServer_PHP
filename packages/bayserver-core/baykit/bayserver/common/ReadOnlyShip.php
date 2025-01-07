<?php

namespace baykit\bayserver\common;


use baykit\bayserver\BayLog;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\ship\Ship;
use baykit\bayserver\Sink;

abstract class ReadOnlyShip extends Ship
{
    /////////////////////////////////////
    // Implements Reusable
    /////////////////////////////////////
    public function reset(): void {
        parent::reset();
    }

    /////////////////////////////////////
    // Implements Ship
    /////////////////////////////////////

    public final function notifyHandshakeDone(string $pcl) : int{
        throw new Sink();
    }

    public final function notifyConnect(): int {
        throw new Sink();
    }

    public final function notifyProtocolError(ProtocolException $e): bool {
        BayLog::error($e);
        throw new Sink();
    }
}