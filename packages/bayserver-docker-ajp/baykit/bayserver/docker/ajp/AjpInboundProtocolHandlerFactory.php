<?php

namespace baykit\bayserver\docker\ajp;

use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\protocol\ProtocolHandlerFactory;

class AjpInboundProtocolHandlerFactory implements ProtocolHandlerFactory
{
    public function createProtocolHandler($pktStore) : ProtocolHandler
    {
        $inboundHandler = new AjpInboundHandler();
        $commandUnpacker = new AjpCommandUnPacker($inboundHandler);
        $packetUnpacker = new AjpPacketUnPacker($pktStore, $commandUnpacker);
        $packetPacker = new PacketPacker();
        $commandPacker = new CommandPacker($packetPacker, $pktStore);
        $protocolHandler =
            new AjpProtocolHandler(
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

