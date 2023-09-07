<?php

namespace baykit\bayserver\docker\ajp;

use baykit\bayserver\docker\base\PortBase;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandlerStore;


class AjpPortDocker extends PortBase implements AjpDocker
{

    const DEFAULT_SUPPORT_H2 = true;

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
    AjpDocker::PROTO_NAME,
    new AjpPacketFactory()
);



ProtocolHandlerStore::registerProtocol(
    AjpDocker::PROTO_NAME,
    true,
    new AjpInboundProtocolHandlerFactory());
