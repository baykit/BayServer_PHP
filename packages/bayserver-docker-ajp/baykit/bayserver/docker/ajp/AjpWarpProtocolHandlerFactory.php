<?php

namespace baykit\bayserver\docker\ajp;

use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\CommandStore;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\protocol\ProtocolHandlerFactory;

class AjpWarpProtocolHandlerFactory implements ProtocolHandlerFactory
{
    public function createProtocolHandler(PacketStore $pktStore, CommandStore $cmdStore) : ProtocolHandler
    {
        $warpHandler = new AjpWarpHandler();
        $commandUnpacker = new AjpCommandUnPacker($warpHandler);
        $packetUnpacker = new AjpPacketUnPacker($pktStore, $commandUnpacker);
        $packetPacker = new PacketPacker();
        $commandPacker = new CommandPacker($packetPacker, $pktStore);
        $protocolHandler =
            new AjpProtocolHandler($warpHandler,
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
