<?php

namespace baykit\bayserver\docker\fcgi;


use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\transporter\PlainTransporter;
use baykit\bayserver\BayLog;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\bcf\BcfKeyVal;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\docker\warp\WarpDocker;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandlerStore;
use baykit\bayserver\util\IOUtil;

class FcgWarpDocker extends WarpDocker implements FcgDocker
{

    public $scriptBase;
    public $docRoot;

    //////////////////////////////////////////////////////
    // Implements Docker
    //////////////////////////////////////////////////////

    public function init(BcfElement $elm, ?Docker $parent): void
    {
        parent::init($elm, $parent);

        if ($this->scriptBase == null)
            BayLog::warn("docRoot is not specified");
    }

    //////////////////////////////////////////////////////
    // Implements DockerBase
    //////////////////////////////////////////////////////

    public function initKeyVal(BcfKeyVal $kv): bool
    {
        switch(strtolower($kv->key)) {
            default:
                return parent::initKeyVal($kv);

            case "scriptbase":
                $this->scriptBase = $kv->value;
                break;

            case "docroot":
                $this->docRoot = $kv->value;
                break;
        }
        return true;
    }



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
        return FcgDocker::PROTO_NAME;
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
    FcgDocker::PROTO_NAME,
    new FcgPacketFactory()
);

ProtocolHandlerStore::registerProtocol(
    FcgDocker::PROTO_NAME,
    false,
    new FcgiWarpProtocolHandlerFactory());

