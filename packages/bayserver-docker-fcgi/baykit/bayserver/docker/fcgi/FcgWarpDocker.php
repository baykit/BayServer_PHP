<?php

namespace baykit\bayserver\docker\fcgi;


use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\multiplexer\PlainTransporter;
use baykit\bayserver\BayLog;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\bcf\BcfKeyVal;
use baykit\bayserver\common\Transporter;
use baykit\bayserver\docker\base\WarpBase;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandlerStore;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\ship\Ship;
use baykit\bayserver\util\IOUtil;

class FcgWarpDocker extends WarpBase implements FcgDocker
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
    FcgDocker::PROTO_NAME,
    new FcgPacketFactory()
);

ProtocolHandlerStore::registerProtocol(
    FcgDocker::PROTO_NAME,
    false,
    new FcgWarpProtocolHandlerFactory());

