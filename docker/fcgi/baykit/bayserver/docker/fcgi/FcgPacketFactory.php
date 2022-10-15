<?php

namespace baykit\bayserver\docker\fcgi;

use baykit\bayserver\protocol\Packet;
use baykit\bayserver\protocol\PacketFactory;

class FcgPacketFactory implements PacketFactory
{
    public function createPacket(int $type): Packet
    {
        return new FcgPacket($type);
    }
}