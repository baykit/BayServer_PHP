<?php

namespace baykit\bayserver\docker\http\h1;



use baykit\bayserver\docker\http\HtpDocker;
use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandler;

abstract class H1ProtocolHandler extends ProtocolHandler implements H1CommandHandler {

    public $keeping;

    public function __construct(PacketStore $pktStore, bool $svrMode)
    {
        $this->commandUnpacker = new H1CommandUnPacker($this, $svrMode);
        $this->packetUnpacker = new H1PacketUnPacker($this->commandUnpacker, $pktStore);
        $this->packetPacker = new PacketPacker();
        $this->commandPacker = new CommandPacker($this->packetPacker, $pktStore);
        $this->server_mode = $svrMode;
        $this->keeping = false;
    }

    //////////////////////////////////////////////////////////////////
    // Implements Reusable
    //////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        parent::reset();
        $this->keeping = false;
    }


    //////////////////////////////////////////////////////////////////
    // Implements ProtocolHandler
    //////////////////////////////////////////////////////////////////

    public function protocol(): string
    {
        return HtpDocker::H1_PROTO_NAME;
    }

    public function maxReqPacketDataSize(): int
    {
        return H1Packet::MAX_DATA_LEN;
    }

    public function maxResPacketDataSize(): int
    {
        return H1Packet::MAX_DATA_LEN;
    }
}