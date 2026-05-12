<?php
namespace baykit\bayserver\docker\h3;

use baykit\bayserver\protocol\Packet;

/**
 * Dummy packet for the H3 command pipeline. QUIC frames are decoded
 * inside libquiche; we never round-trip raw H3 frames through the
 * BayServer PacketStore / PacketPacker pipeline. This class only
 * exists to satisfy the ProtocolHandler base type.
 */
class QicCommandPacket extends Packet
{
    public function __construct(int $type)
    {
        parent::__construct($type, 0, 0);
    }

    public function __toString() : string
    {
        return "QicCmd packet[type={$this->type}]";
    }
}
