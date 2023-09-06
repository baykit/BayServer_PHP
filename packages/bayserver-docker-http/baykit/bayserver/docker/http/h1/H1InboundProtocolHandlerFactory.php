<?php

namespace baykit\bayserver\docker\http\h1;

use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\protocol\ProtocolHandlerFactory;

class H1InboundProtocolHandlerFactory implements ProtocolHandlerFactory
{
    public function createProtocolHandler(PacketStore $pktStore): ProtocolHandler
    {
        return new H1InboundHandler($pktStore);
    }
}

