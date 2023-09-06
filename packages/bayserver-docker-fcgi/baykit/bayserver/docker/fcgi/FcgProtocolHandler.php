<?php

namespace baykit\bayserver\docker\fcgi;

use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\ProtocolHandler;

abstract class FcgProtocolHandler extends ProtocolHandler implements FcgCommandHandler
{

    public function __construct($pktStore, $svrMode)
    {
        $this->commandUnpacker = new FcgCommandUnPacker($this);
        $this->packetUnpacker = new FcgPacketUnPacker($pktStore, $this->commandUnpacker);
        $this->packetPacker = new PacketPacker();
        $this->commandPacker = new CommandPacker($this->packetPacker, $pktStore);
        $this->serverMode = $svrMode;
    }

    /////////////////////////////////////////////
    // Implements ProtocolHandler
    /////////////////////////////////////////////
    public function protocol() : string
    {
        return FcgDocker::PROTO_NAME;
    }

    public function maxReqPacketDataSize() : int
    {
        return FcgPacket::MAXLEN;
    }

    public function maxResPacketDataSize() : int
    {
        return FcgPacket::MAXLEN;
    }
}