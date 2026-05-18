<?php

namespace baykit\bayserver\docker\fcgi;

use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\CommandStore;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\protocol\ProtocolHandlerFactory;

class FcgInboundProtocolHandlerFactory implements ProtocolHandlerFactory
{
    public function createProtocolHandler(PacketStore $pktStore, CommandStore $cmdStore) : ProtocolHandler
    {
        $inboundHandler = new FcgInboundHandler();
        $commandUnpacker = new FcgCommandUnPacker($inboundHandler, $cmdStore);
        $packetUnpacker = new FcgPacketUnPacker($pktStore, $commandUnpacker);
        $packetPacker = new PacketPacker();
        $commandPacker = new CommandPacker($packetPacker, $pktStore);
        $protocolHandler =
            new FcgProtocolHandler($inboundHandler,
                $packetUnpacker,
                $packetPacker,
                $commandUnpacker,
                $commandPacker,
                $cmdStore,
                true);
        $inboundHandler->init($protocolHandler);
        return $protocolHandler;
    }
}
