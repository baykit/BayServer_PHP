<?php
namespace baykit\bayserver\protocol;


interface ProtocolHandlerFactory
{
    public function createProtocolHandler(PacketStore $pktStore, CommandStore $cmdStore) : ProtocolHandler;
}