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
}