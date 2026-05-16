<?php

namespace baykit\bayserver\docker;

use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\Headers;

/**
 * "Barge" is a metaphor for the cache management function.
 *
 * Implementations decide whether a request can ride on a cached "Cargo"
 * (the bytes of a previously rendered response) and, if so, hand back the
 * Cargo + an optional Rudder used by waiters that need to block on the
 * cargo being loaded by another concurrent request.
 */
interface Barge extends Docker
{
    /** Barge name (path) */
    public function name() : string;

    /** Capacity of the barge in mega-bytes */
    public function capacity() : int;

    /**
     * Get cargo on the barge.
     *
     * Returns [Barge_Cargo|null cargo, Rudder|null waitRudder].
     * - When cargo is already loaded, returns [cargo, null] and the caller
     * serves directly from cargo->content().
     * - When cargo is in flight, returns [cargo, rudder] and the caller
     * wires up a waiter on the rudder.
     * - When path is uncacheable, returns [null, null].
     */
    public function getCargo(Tour $tour) : array;
}
