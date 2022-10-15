<?php

namespace baykit\bayserver\docker\http;

use baykit\bayserver\docker\base\PortBase;
use baykit\bayserver\docker\http\h1\H1InboundProtocolHandlerFactory;
use baykit\bayserver\docker\http\h1\H1PacketFactory;
use baykit\bayserver\docker\http\h2\H2InboundProtocolHandlerFactory;
use baykit\bayserver\docker\http\h2\H2PacketFactory;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandlerStore;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\docker\http\h2\H2ErrorCode;

class HtpPortDocker extends PortBase implements HtpDocker
{

    const DEFAULT_SUPPORT_H2 = true;

    public $supportH2 = self::DEFAULT_SUPPORT_H2;

    public function __construct()
    {
        parent::__construct();
    }

    //////////////////////////////////////////////////////
    // Implements Docker
    //////////////////////////////////////////////////////

    public function init($elm, $parent): void
    {
        parent::init($elm, $parent);

        if ($this->supportH2) {
            if ($this->secure())
                $this->secureDocker->setAppProtocols("h2, http/1.1");
            H2ErrorCode::initCodes();
        }
    }

    //////////////////////////////////////////////////////
    // Implements DockerBase
    //////////////////////////////////////////////////////

    public function initKeyVal($kv): bool
    {
        $key = strtolower($kv->key);
        if ($key == "supporth2" or $key == "enableh2")
            $this->supportH2 = StringUtil::parseBool($kv->value);
        else
            return parent::initKeyVal($kv);
        return true;
    }


    //////////////////////////////////////////////////////
    // Implements Port
    //////////////////////////////////////////////////////

    public function protocol(): string
    {
        return self::H1_PROTO_NAME;
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
    HtpDocker::H1_PROTO_NAME,
    new H1PacketFactory()
);

PacketStore::registerProtocol(
    HtpDocker::H2_PROTO_NAME,
    new H2PacketFactory()
);


ProtocolHandlerStore::registerProtocol(
    HtpDocker::H1_PROTO_NAME,
    true,
    new H1InboundProtocolHandlerFactory());

ProtocolHandlerStore::registerProtocol(
    HtpDocker::H2_PROTO_NAME,
    true,
    new H2InboundProtocolHandlerFactory());
