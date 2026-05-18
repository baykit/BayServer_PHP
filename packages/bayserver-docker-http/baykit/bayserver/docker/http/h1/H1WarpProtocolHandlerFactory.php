<?php

namespace baykit\bayserver\docker\http\h1;

use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\CommandStore;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\protocol\ProtocolHandlerFactory;

class H1WarpProtocolHandlerFactory implements ProtocolHandlerFactory
{
    public function createProtocolHandler(PacketStore $pktStore, CommandStore $cmdStore): ProtocolHandler
    {
        $warpHandler = new H1WarpHandler();
        $commandUnpacker = new H1CommandUnPacker($warpHandler, $cmdStore, false);
        $packetUnpacker = new H1PacketUnPacker($commandUnpacker, $pktStore);
        $packetPacker = new PacketPacker();
        $commandPacker = new CommandPacker($packetPacker, $pktStore);
        $protocolHandler =
            new H1ProtocolHandler($warpHandler,
                $packetUnpacker,
                $packetPacker,
                $commandUnpacker,
                $commandPacker,
                $cmdStore,
                false);
        $warpHandler->init($protocolHandler);
        return $protocolHandler;
    }
}
