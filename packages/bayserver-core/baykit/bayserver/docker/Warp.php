<?php
namespace baykit\bayserver\docker;

use baykit\bayserver\ship\Ship;

interface Warp extends Club
{
    public function host() : string;
    public function port() : int;
    public function warpBase() : string;
    public function timeoutSec() : int;
    public function keep(Ship $warpShip) : void;
    public function onEndShip(Ship $warpShip) : void;

    /**
     * Mark a ship as no longer eligible for sharing new tours (= remove
     * from the multiplex pool, if any). Used by H2 warp handlers when
     * the backend signals GOAWAY: in-flight tours can still drain on
     * the existing connection, but no new tours should attach. Default
     * no-op for non-multiplex dockers.
     */
    public function excludeFromPool(Ship $warpShip) : void;
}