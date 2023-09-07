<?php

namespace baykit\bayserver\docker\ajp;

use baykit\bayserver\protocol\Packet;
use baykit\bayserver\protocol\PacketFactory;

class AjpPacketFactory implements PacketFactory
{
    public function createPacket(int $type): Packet
    {
        return new AjpPacket($type);
    }
}