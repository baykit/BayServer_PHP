<?php

namespace baykit\bayserver\docker\http\h1;



use baykit\bayserver\docker\http\HtpDocker;
use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\PacketUnPacker;
use baykit\bayserver\protocol\ProtocolHandler;

class H1ProtocolHandler extends ProtocolHandler{

    public $keeping;

    public function __construct(
                        H1Handler $h1Handler,
                        H1PacketUnpacker $packetUnpacker,
                        PacketPacker $packetPacker,
                        H1CommandUnpacker $commandUnpacker,
                        CommandPacker $commandPacker,
                        bool $svrMode)
    {
        parent::__construct($packetUnpacker, $packetPacker, $commandUnpacker, $commandPacker, $h1Handler, $svrMode);
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