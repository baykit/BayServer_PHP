<?php

namespace baykit\bayserver\protocol;

interface PacketFactory
{
    public function createPacket(int $type) : Packet;
}