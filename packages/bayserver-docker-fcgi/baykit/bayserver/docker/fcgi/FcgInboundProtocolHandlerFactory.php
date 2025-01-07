<?php

namespace baykit\bayserver\docker\fcgi;

use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\protocol\ProtocolHandlerFactory;

class FcgInboundProtocolHandlerFactory implements ProtocolHandlerFactory
{
    public function createProtocolHandler($pktStore) : ProtocolHandler
    {
        $inboundHandler = new FcgInboundHandler();
        $commandUnpacker = new FcgCommandUnPacker($inboundHandler);
        $packetUnpacker = new FcgPacketUnPacker($pktStore, $commandUnpacker);
        $packetPacker = new PacketPacker();
        $commandPacker = new CommandPacker($packetPacker, $pktStore);
        $protocolHandler =
            new FcgProtocolHandler(
                $inboundHandler,
                $packetUnpacker,
                $packetPacker,
                $commandUnpacker,
                $commandPacker,
                true);
        $inboundHandler->init($protocolHandler);
        return $protocolHandler;
    }
}

