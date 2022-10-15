<?php

namespace baykit\bayserver\docker\fcgi;

use baykit\bayserver\docker\base\PortBase;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandlerStore;


class FcgPortDocker extends PortBase implements FcgDocker
{

    //////////////////////////////////////////////////////
    // Implements Port
    //////////////////////////////////////////////////////

    public function protocol(): string
    {
        return self::PROTO_NAME;
    }


    ///////////////////////////////////////////////////////////////////////
    // Implements PortBase
    ///////////////////////////////////////////////////////////////////////
    protected function supportAnchored() : bool
    {
        return true;
    }

    protected function supportUnanchored() : bool
    {
        return false;
    }
}

//////////////////////////////////////////////////////
// Class initializer
//////////////////////////////////////////////////////
PacketStore::registerProtocol(
    FcgDocker::PROTO_NAME,
    new FcgPacketFactory()
);



ProtocolHandlerStore::registerProtocol(
    FcgDocker::PROTO_NAME,
    true,
    new FcgInboundProtocolHandlerFactory());
