<?php

namespace baykit\bayserver\docker\http\h1;

use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\CommandStore;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\protocol\ProtocolHandlerFactory;

class H1InboundProtocolHandlerFactory implements ProtocolHandlerFactory
{
    public function createProtocolHandler(PacketStore $pktStore, CommandStore $cmdStore): ProtocolHandler
    {
        $inboundHandler = new H1InboundHandler();
        $commandUnpacker = new H1CommandUnPacker($inboundHandler, $cmdStore, true);
        $packetUnpacker = new H1PacketUnPacker($commandUnpacker, $pktStore);
        $packetPacker = new PacketPacker();
        $commandPacker = new CommandPacker($packetPacker, $pktStore);
        $protocolHandler =
            new H1ProtocolHandler($inboundHandler,
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
