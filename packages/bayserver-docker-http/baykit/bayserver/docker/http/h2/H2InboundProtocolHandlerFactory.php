<?php

namespace baykit\bayserver\docker\http\h2;

use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\CommandStore;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\protocol\ProtocolHandlerFactory;

class H2InboundProtocolHandlerFactory implements ProtocolHandlerFactory
{
    public function createProtocolHandler(PacketStore $pktStore, CommandStore $cmdStore): ProtocolHandler
    {
        $inboundHandler = new H2InboundHandler();
        $commandUnpacker = new H2CommandUnPacker($inboundHandler, $cmdStore);
        $packetUnpacker = new H2PacketUnPacker($commandUnpacker, $pktStore, true);
        $packetPacker = new PacketPacker();
        $commandPacker = new CommandPacker($packetPacker, $pktStore);
        $protocolHandler =
            new H2ProtocolHandler($inboundHandler,
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
