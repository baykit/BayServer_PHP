<?php

namespace baykit\bayserver\docker\http\h2;

use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\ProtocolHandler;

class H2ProtocolHandler extends ProtocolHandler
{
    const CTL_STREAM_ID = 0;

    public function __construct(
                        H2Handler $h2Handler,
                        H2PacketUnPacker $packetUnPacker,
                        PacketPacker $packetPacker,
                        H2CommandUnPacker $commandUnPacker,
                        CommandPacker $commandPacker,
                        bool $svrMode)
    {
        parent::__construct($packetUnPacker, $packetPacker, $commandUnPacker, $commandPacker, $h2Handler, $svrMode);
    }

    ////////////////////////////////////////////////////
    // implements ProtocolHandler
    ////////////////////////////////////////////////////
    public function maxReqPacketDataSize() : int
    {
        return H2Packet::DEFAULT_PAYLOAD_MAXLEN;
    }

    public function maxResPacketDataSize() : int
    {
        return H2Packet::DEFAULT_PAYLOAD_MAXLEN;
    }

    public function protocol() : string
    {
        return "h2";
    }
}