<?php

namespace baykit\bayserver\docker\fcgi;

use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\ProtocolHandler;

class FcgProtocolHandler extends ProtocolHandler
{

    public function __construct(
                        FcgHandler $h1Handler,
                        FcgPacketUnpacker $packetUnpacker,
                        PacketPacker $packetPacker,
                        FcgCommandUnpacker $commandUnpacker,
                        CommandPacker $commandPacker,
                        bool $svrMode)
    {
        parent::__construct($packetUnpacker, $packetPacker, $commandUnpacker, $commandPacker, $h1Handler, $svrMode);
        $this->commandHandler->reset();
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