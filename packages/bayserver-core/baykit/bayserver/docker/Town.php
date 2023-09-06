<?php
namespace baykit\bayserver\docker;

use baykit\bayserver\tour\Tour;

interface Town extends Docker
{
    const MATCH_TYPE_MATCHED = 1;
    const MATCH_TYPE_NOT_MATCHED = 2;
    const MATCH_TYPE_CLOSE = 3;

    public function reroute(String $uri) : string;
    public function matches(String $uri) : int;
    public function checkAdmitted(Tour $tur) : void;
}