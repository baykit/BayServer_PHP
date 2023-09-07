<?php

namespace baykit\bayserver\agent;

use baykit\bayserver\BayLog;

class PortMap
{
    public $ch;
    public $docker;

    public function __construct($ch, $docker)
    {
        $this->ch = $ch;
        $this->docker = $docker;
    }

    public static function findDocker($ch, array $map_list)
    {
        foreach($map_list as $map) {
            if($map->ch == $ch)
                return $map->docker;
        }
        return false;
    }
}