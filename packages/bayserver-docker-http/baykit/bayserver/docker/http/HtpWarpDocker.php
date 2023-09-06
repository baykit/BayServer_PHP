<?php

namespace baykit\bayserver\docker\http;


use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\transporter\PlainTransporter;
use baykit\bayserver\agent\transporter\SecureTransporter;
use baykit\bayserver\BayMessage;
use baykit\bayserver\ConfigException;
use baykit\bayserver\docker\http\h1\H1PacketFactory;
use baykit\bayserver\docker\http\h1\H1WarpProtocolHandlerFactory;
use baykit\bayserver\docker\http\h2\H2PacketFactory;
use baykit\bayserver\docker\warp\WarpDocker;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandlerStore;
use baykit\bayserver\Symbol;
use baykit\bayserver\util\IOUtil;
use baykit\bayserver\util\StringUtil;

class HtpWarpDocker extends WarpDocker implements HtpDocker
{

    private $secure;
    private $supportH2 = true;

    private $traceSSL = false;

    private $sslCtx;


    //////////////////////////////////////////////////////
    // Implements Docker
    //////////////////////////////////////////////////////

    public function init($elm, $parent): void
    {
        parent::init($elm, $parent);

        if($this->secure) {
            try {
                $this->sslCtx = stream_context_create();
                stream_context_set_option($this->sslctx, 'ssl', 'allow_self_signed', true);
                stream_context_set_option($this->sslctx, 'ssl', 'verify_peer', false);
                stream_context_set_option($this->sslctx, 'ssl', 'verify_peer_name', false);
            } catch (\Exception $e) {
                throw new ConfigException($elm->fileName, $elm->lineNo, $e, BayMessage::get(Symbol::CFG_SSL_INIT_ERROR), $e);
            }
        }
    }

    //////////////////////////////////////////////////////
    // Implements DockerBase
    //////////////////////////////////////////////////////

    public function initKeyVal($kv): bool
    {
        $key = strtolower($kv->key);
        switch($key) {
            case "supporth2":
                $this->supportH2 = StringUtil::parseBool($kv->value);
                break;

            case "tracessl":
                $this->traceSSL = StringUtil::parseBool($kv->value);
                break;

            case "secure":
                $this->secure = StringUtil::parseBool($kv->value);
                break;

            default:
                return parent::initKeyVal($kv);
        }
        return true;
    }


    //////////////////////////////////////////////////////
    // Implements WarpDocker
    //////////////////////////////////////////////////////

    public function secure(): bool
    {
        return $this->secure;
    }


    ///////////////////////////////////////////////////////////////////////
    // Implements WarpDockerBase
    ///////////////////////////////////////////////////////////////////////
    protected function protocol(): string
    {
        return self::H1_PROTO_NAME;
    }

    protected function newTransporter(GrandAgent $agent, $ch)
    {
        if($this->secure) {
            return new SecureTransporter($this->sslCtx, false, IOUtil::getSockRecvBufSize($ch), $this->traceSSL);
        }
        else
            return new PlainTransporter(false, IOUtil::getSockRecvBufSize($ch));
    }


}

//////////////////////////////////////////////////////
// Class initializer
//////////////////////////////////////////////////////
PacketStore::registerProtocol(
    HtpDocker::H1_PROTO_NAME,
    new H1PacketFactory()
);

ProtocolHandlerStore::registerProtocol(
    HtpDocker::H1_PROTO_NAME,
    false,
    new H1WarpProtocolHandlerFactory());

