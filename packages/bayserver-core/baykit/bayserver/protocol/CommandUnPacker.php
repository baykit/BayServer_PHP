<?php

namespace baykit\bayserver\protocol;

use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\util\Reusable;

abstract class CommandUnPacker implements Reusable
{
    public abstract function packetReceived(Packet $pkt) : int;
}