<?php

namespace baykit\bayserver\docker\ajp;

use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\protocol\ProtocolHandlerFactory;

class AjpInboundProtocolHandlerFactory implements ProtocolHandlerFactory
{
    public function createProtocolHandler($pktStore) : ProtocolHandler
    {
        return new AjpInboundHandler($pktStore);
    }
}

