<?php

namespace baykit\bayserver\docker\http\h2;

use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\protocol\ProtocolHandlerFactory;

class H2InboundProtocolHandlerFactory implements ProtocolHandlerFactory
{
    public function createProtocolHandler(PacketStore $pktStore): ProtocolHandler
    {
        return new H2InboundHandler($pktStore);
    }
}

