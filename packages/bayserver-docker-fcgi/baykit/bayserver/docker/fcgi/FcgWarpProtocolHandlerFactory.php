<?php

namespace baykit\bayserver\docker\fcgi;

use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\protocol\ProtocolHandlerFactory;

class FcgWarpProtocolHandlerFactory implements ProtocolHandlerFactory
{
    public function createProtocolHandler($pktStore) : ProtocolHandler
    {
        $warpHandler = new FcgWarpHandler();
        $commandUnpacker = new FcgCommandUnPacker($warpHandler);
        $packetUnpacker = new FcgPacketUnPacker($pktStore, $commandUnpacker);
        $packetPacker = new PacketPacker();
        $commandPacker = new CommandPacker($packetPacker, $pktStore);
        $protocolHandler =
            new FcgProtocolHandler(
                $warpHandler,
                $packetUnpacker,
                $packetPacker,
                $commandUnpacker,
                $commandPacker,
                false);
        $warpHandler->init($protocolHandler);
        return $protocolHandler;
    }
}

