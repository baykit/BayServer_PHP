<?php

namespace baykit\bayserver\docker\http\h1;

use baykit\bayserver\protocol\Packet;
use baykit\bayserver\protocol\PacketFactory;

class H1PacketFactory implements PacketFactory
{
    public function createPacket(int $type): Packet
    {
        return new H1Packet($type);
    }
}