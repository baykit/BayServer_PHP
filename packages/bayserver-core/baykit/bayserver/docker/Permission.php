<?php
namespace baykit\bayserver\docker;

use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\tour\Tour;

interface Permission extends Docker
{
    public function socketAdmitted(Rudder $rd) : void;

    public function tourAdmitted(Tour $tur) : void;
}