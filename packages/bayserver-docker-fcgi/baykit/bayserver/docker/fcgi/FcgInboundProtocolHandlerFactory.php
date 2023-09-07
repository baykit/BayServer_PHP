<?php

namespace baykit\bayserver\docker\fcgi;

use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\protocol\ProtocolHandlerFactory;

class FcgInboundProtocolHandlerFactory implements ProtocolHandlerFactory
{
    public function createProtocolHandler($pktStore) : ProtocolHandler
    {
        return new FcgInboundHandler($pktStore);
    }
}

