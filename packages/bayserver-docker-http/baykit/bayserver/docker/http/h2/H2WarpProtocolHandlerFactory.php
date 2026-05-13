<?php
namespace baykit\bayserver\docker\http\h2;

use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\protocol\ProtocolHandlerFactory;

/**
 * Builds an {@link H2ProtocolHandler} configured as the client
 * (= warp-out) side: sends the H2 preface and SETTINGS, receives
 * HEADERS / DATA / GOAWAY from the backend. WarpProtocolHandlerFactory.
 */
class H2WarpProtocolHandlerFactory implements ProtocolHandlerFactory
{
    public function createProtocolHandler(PacketStore $pktStore): ProtocolHandler
    {
        $warpHandler = new H2WarpHandler();
        $commandUnpacker = new H2CommandUnPacker($warpHandler);
        $packetUnpacker = new H2PacketUnPacker($commandUnpacker, $pktStore);
        $packetPacker = new PacketPacker();
        $commandPacker = new CommandPacker($packetPacker, $pktStore);
        // server_mode=false: this is the client side of an H2
        // connection (we send the preface, never receive one).
        $protocolHandler = new H2ProtocolHandler($warpHandler,
            $packetUnpacker,
            $packetPacker,
            $commandUnpacker,
            $commandPacker,
            false);
        $warpHandler->init($protocolHandler);
        return $protocolHandler;
    }
}
