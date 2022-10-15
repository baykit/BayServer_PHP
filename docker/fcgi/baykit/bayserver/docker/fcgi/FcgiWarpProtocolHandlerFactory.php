<?php

namespace baykit\bayserver\docker\fcgi;

use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\protocol\ProtocolHandlerFactory;

class FcgiWarpProtocolHandlerFactory implements ProtocolHandlerFactory
{
    public function createProtocolHandler($pktStore) : ProtocolHandler
    {
        return new FcgWarpHandler($pktStore);
    }
}

