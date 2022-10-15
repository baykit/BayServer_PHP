<?php

namespace baykit\bayserver\protocol;

use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\util\Reusable;

abstract class PacketUnPacker implements Reusable
{
    public abstract function bytesReceived(string $bytes) : int;
}