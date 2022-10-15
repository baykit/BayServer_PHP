<?php

namespace baykit\bayserver\docker\ajp;

use baykit\bayserver\docker\ajp\command\CmdData;
use baykit\bayserver\docker\ajp\command\CmdSendBodyChunk;
use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\ProtocolHandler;

abstract class AjpProtocolHandler extends ProtocolHandler implements AjpCommandHandler
{

    public function __construct($pktStore, $svrMode)
    {
        $this->commandUnpacker = new AjpCommandUnPacker($this);
        $this->packetUnpacker = new AjpPacketUnPacker($pktStore, $this->commandUnpacker);
        $this->packetPacker = new PacketPacker();
        $this->commandPacker = new CommandPacker($this->packetPacker, $pktStore);
        $this->serverMode = $svrMode;
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