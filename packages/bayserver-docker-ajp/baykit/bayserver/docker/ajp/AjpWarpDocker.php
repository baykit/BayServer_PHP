<?php

namespace baykit\bayserver\docker\ajp;


use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\multiplexer\PlainTransporter;
use baykit\bayserver\common\Transporter;
use baykit\bayserver\docker\base\WarpBase;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandlerStore;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\ship\Ship;
use baykit\bayserver\util\IOUtil;

class AjpWarpDocker extends WarpBase implements AjpDocker
{
    //////////////////////////////////////////////////////
    // Implements WarpDocker
    //////////////////////////////////////////////////////

    public function secure(): bool
    {
        return false;
    }


    ///////////////////////////////////////////////////////////////////////
    // Implements WarpDockerBase
    ///////////////////////////////////////////////////////////////////////
    protected function protocol(): string
    {
        return AjpDocker::PROTO_NAME;
    }

    protected function newTransporter(GrandAgent $agent, Rudder $rd, Ship $sip): Transporter
    {
        $tp = new PlainTransporter(
            $agent->netMultiplexer,
            $sip,
            false,
            IOUtil::getSockRecvBufSize($rd->key()),
            false);

        return $tp;
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
    false,
    new AjpWarpProtocolHandlerFactory());

