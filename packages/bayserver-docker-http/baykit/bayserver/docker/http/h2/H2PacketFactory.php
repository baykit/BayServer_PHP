<?php

namespace baykit\bayserver\docker\http\h2;

use baykit\bayserver\protocol\Packet;
use baykit\bayserver\protocol\PacketFactory;

class H2PacketFactory implements PacketFactory
{
    public function createPacket(int $type): Packet
    {
        return new H2Packet($type);
    }
}