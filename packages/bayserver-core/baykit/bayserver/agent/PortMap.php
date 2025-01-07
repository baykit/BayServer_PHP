<?php

namespace baykit\bayserver\agent;

use baykit\bayserver\docker\Port;
use baykit\bayserver\rudder\Rudder;

class PortMap
{
    public Rudder $rudder;
    public Port $docker;

    public function __construct(Rudder $rd, Port $dkr)
    {
        $this->rudder = $rd;
        $this->docker = $dkr;
    }

    public static function findDocker(Rudder $rd, array $map_list)
    {
        foreach($map_list as $map) {
            if($map->rudder == $rd)
                return $map->docker;
        }
        return false;
    }
}