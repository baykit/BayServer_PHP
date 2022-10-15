<?php
namespace baykit\bayserver\docker;

use baykit\bayserver\tour\Tour;

interface Permission extends Docker
{
    public function socketAdmitted($ch) : void;

    public function tourAdmitted(Tour $tur) : void;
}