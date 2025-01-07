<?php

namespace baykit\bayserver\docker\ajp;

use baykit\bayserver\docker\ajp\command\CmdData;
use baykit\bayserver\docker\ajp\command\CmdSendBodyChunk;
use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\ProtocolHandler;

class AjpProtocolHandler extends ProtocolHandler
{

    public function __construct(
                        AjpHandler $ajpHandler,
                        AjpPacketUnpacker $packetUnpacker,
                        PacketPacker $packetPacker,
                        AjpCommandUnpacker $commandUnpacker,
                        CommandPacker $commandPacker,
                        bool $svrMode)
    {
        parent::__construct($packetUnpacker, $packetPacker, $commandUnpacker, $commandPacker, $ajpHandler, $svrMode);
    }

    /////////////////////////////////////////////
    // Implements ProtocolHandler
    /////////////////////////////////////////////
    public function protocol() : string
    {
        return AjpDocker::PROTO_NAME;
    }

    public function maxReqPacketDataSize() : int
    {
        return CmdData::MAX_DATA_LEN;
    }

    public function maxResPacketDataSize() : int
    {
        return CmdSendBodyChunk::MAX_CHUNKLEN;
    }


}