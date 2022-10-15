<?php

namespace baykit\bayserver\docker\http\h2;

use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandler;

abstract class H2ProtocolHandler extends ProtocolHandler implements H2CommandHandler
{
    const CTL_STREAM_ID = 0;

    public $reqHeaderTbl;
    public $resHeaderTbl;


    public function __construct(PacketStore $pktStore, bool $svrMode)
    {
        $this->commandUnpacker = new H2CommandUnPacker($this);
        $this->packetUnpacker = new H2PacketUnPacker($this->commandUnpacker, $pktStore, $svrMode);
        $this->packetPacker = new PacketPacker();
        $this->commandPacker = new CommandPacker($this->packetPacker, $pktStore);
        $this->serverMode = $svrMode;
        $this->reqHeaderTbl = HeaderTable::createDynamicTable();
        $this->resHeaderTbl = HeaderTable::createDynamicTable();
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