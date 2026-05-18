<?php

namespace baykit\bayserver\docker\http;


use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\LifecycleListener;
use baykit\bayserver\agent\multiplexer\PlainTransporter;
use baykit\bayserver\agent\multiplexer\SecureTransporter;
use baykit\bayserver\BayMessage;
use baykit\bayserver\common\Transporter;
use baykit\bayserver\common\WarpShip;
use baykit\bayserver\ConfigException;
use baykit\bayserver\docker\base\WarpBase;
use baykit\bayserver\docker\http\h1\H1PacketFactory;
use baykit\bayserver\docker\http\h1\H1WarpProtocolHandlerFactory;
use baykit\bayserver\docker\http\h2\H2PacketFactory;
use baykit\bayserver\docker\http\h2\H2WarpProtocolHandlerFactory;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandlerStore;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\ship\Ship;
use baykit\bayserver\Symbol;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\IOUtil;
use baykit\bayserver\util\StringUtil;

/** Per-docker listener that owns the WarpShipPool map. */
class HtpWarpDocker_AgentListener implements LifecycleListener
{
    private HtpWarpDocker $docker;

    public function __construct(HtpWarpDocker $dkr)
    {
        $this->docker = $dkr;
    }

    public function add(int $agtId) : void
    {
        $this->docker->pools[$agtId] = new WarpShipPool();
    }

    public function remove(int $agtId) : void
    {
        unset($this->docker->pools[$agtId]);
    }
}

class HtpWarpDocker extends WarpBase implements HtpDocker
{

    private bool $secure = false;
    private bool $supportH2 = true;
    /**
     * When true, the warp side speaks HTTP/2 to the backend and
     * multiplexes many tours over one shared TCP connection.
     * Defaults to false (= H1 per-tour). Mirrors's
     * `enableh2` config flag on httpWarp.
     */
    private bool $enableH2 = false;

    private bool $traceSSL = false;

    private $sslCtx;

    /**
     * Per-agent multiplex pool. Populated only when enableH2 is true;
     * otherwise arrive() falls through to the exclusive rent path
     * inherited from WarpBase.
     *
     * @var array<int, WarpShipPool>
     */
    public array $pools = [];


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

        if ($this->enableH2) {
            // WarpBase already registered an agent listener for the
            // per-agent WarpShipStore. We add a second one here for
            // the multiplex pool.
            GrandAgent::addLifecycleListener(new HtpWarpDocker_AgentListener($this));
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

            case "enableh2":
                $this->enableH2 = StringUtil::parseBool($kv->value);
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
        return $this->enableH2 ? self::H2_PROTO_NAME : self::H1_PROTO_NAME;
    }

    //////////////////////////////////////////////////////
    // Multiplex hooks (only active when enableH2 is true)
    //////////////////////////////////////////////////////

    protected function pickReusableShip(GrandAgent $agt, Tour $tour) : ?WarpShip
    {
        if (!$this->enableH2) return null;
        $pool = $this->pools[$agt->agentId] ?? null;
        return $pool !== null ? $pool->findIdlest() : null;
    }

    protected function onShipRented(GrandAgent $agt, WarpShip $wsip) : void
    {
        if (!$this->enableH2) return;
        $pool = $this->pools[$agt->agentId] ?? null;
        if ($pool !== null) $pool->add($wsip);
    }

    public function keep(Ship $warpShip) : void
    {
        if ($this->enableH2) {
            // Multiplex mode: ship stays in the per-agent pool until
            // the backend connection actually closes. Returning it
            // to the WarpShipStore.keepList here would yank it out
            // of the pool's list of reusable shared connections.
            return;
        }
        parent::keep($warpShip);
    }

    public function onEndShip(Ship $warpShip) : void
    {
        if ($this->enableH2) {
            $pool = $this->pools[$warpShip->agentId] ?? null;
            if ($pool !== null && $warpShip instanceof WarpShip)
                $pool->remove($warpShip);
        }
        parent::onEndShip($warpShip);
    }

    public function excludeFromPool(Ship $warpShip) : void
    {
        if (!$this->enableH2) return;
        $pool = $this->pools[$warpShip->agentId] ?? null;
        if ($pool !== null && $warpShip instanceof WarpShip)
            $pool->remove($warpShip);
    }

    protected function newTransporter(GrandAgent $agent, Rudder $rd, Ship $sip): Transporter
    {
        if($this->secure) {
            $tp = new SecureTransporter($this->sslCtx,
                        $sip,
                        false,
                        IOUtil::getSockRecvBufSize($rd->key()),
                        $this->traceSSL,
                        $this->sslCtx);
        }
        else {
            $tp = new PlainTransporter($agent->netMultiplexer,
                        $sip,
                        false,
                        IOUtil::getSockRecvBufSize($rd->key()),
                        false);
        }
        $tp->init();
        return $tp;
    }


}

//////////////////////////////////////////////////////
// Class initializer
//////////////////////////////////////////////////////
PacketStore::registerProtocol(HtpDocker::H1_PROTO_NAME,
    new H1PacketFactory()
);

\baykit\bayserver\protocol\CommandStore::registerProtocol(HtpDocker::H1_PROTO_NAME,
    new \baykit\bayserver\docker\http\h1\H1CommandFactory()
);

ProtocolHandlerStore::registerProtocol(HtpDocker::H1_PROTO_NAME,
    false,
    new H1WarpProtocolHandlerFactory());

// H2 warp registration.
// Registered unconditionally because the H2 packet factory is the
// same one the inbound H2 listener already uses.
PacketStore::registerProtocol(HtpDocker::H2_PROTO_NAME,
    new H2PacketFactory());

\baykit\bayserver\protocol\CommandStore::registerProtocol(HtpDocker::H2_PROTO_NAME,
    new \baykit\bayserver\docker\http\h2\H2CommandFactory()
);

ProtocolHandlerStore::registerProtocol(HtpDocker::H2_PROTO_NAME,
    false,
    new H2WarpProtocolHandlerFactory());

