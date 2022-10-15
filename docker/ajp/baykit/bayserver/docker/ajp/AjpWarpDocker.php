<?php

namespace baykit\bayserver\docker\ajp;


use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\transporter\PlainTransporter;
use baykit\bayserver\docker\ajp\AjpDocker;
use baykit\bayserver\docker\ajp\AjpPacketFactory;
use baykit\bayserver\docker\ajp\AjpWarpProtocolHandlerFactory;
use baykit\bayserver\docker\warp\WarpDocker;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandlerStore;
use baykit\bayserver\util\IOUtil;

class AjpWarpDocker extends WarpDocker implements AjpDocker
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

    protected function newTransporter(GrandAgent $agent, $ch)
    {
        return new PlainTransporter(false, IOUtil::getSockRecvBufSize($ch));
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

