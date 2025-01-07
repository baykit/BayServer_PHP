<?php

namespace baykit\bayserver\docker\base;


use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\multiplexer\PlainTransporter;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\common\Cities;
use baykit\bayserver\common\InboundShip;
use baykit\bayserver\common\InboundShipStore;
use baykit\bayserver\common\RudderState;
use baykit\bayserver\ConfigException;
use baykit\bayserver\docker\City;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\docker\Permission;
use baykit\bayserver\docker\Port;
use baykit\bayserver\docker\Secure;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\protocol\ProtocolHandlerStore;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\Symbol;
use baykit\bayserver\util\IOUtil;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\SysUtil;

abstract class PortBase extends DockerBase implements Port {

    const DEFAULT_NON_BLOCKING_TIMEOUT_MILLISEC = 1000;

    public $permissionList = [];
    public int $timeoutSec = -1;
    public ?string $host = null;
    public int $port = -1;
    public ?string $socketPath = null;
    public ?Secure $secureDocker = null;
    public bool $anchored = true;
    public $additionalHeaders = [];
    public Cities $cities;
    public int $nonBlockingTimeoutMillis = PortBase::DEFAULT_NON_BLOCKING_TIMEOUT_MILLISEC;

    public function __construct()
    {
        $this->cities = new Cities();
    }

    //////////////////////////////////////////////////////
    // Implements Docker
    //////////////////////////////////////////////////////

    public function init(BcfElement $elm, ?Docker $parent) : void
    {
        if(StringUtil::isEmpty($elm->arg))
            throw new ConfigException($elm->fileName, $elm->lineNo, BayMessage::get(Symbol::CFG_INVALID_PORT_NAME, $elm->name));

        parent::init($elm, $parent);

        $portName = strtolower($elm->arg);
        if(StringUtil::startsWith($portName, ":unix:")) {
            // Unix domain socket
            if(!SysUtil::supportUnixDomainSocketAddress()) {
                throw new ConfigException($elm->fileName, $elm->lineNo, BayMessage::get(Symbol::CFG_CANNOT_SUPPORT_UNIX_DOMAIN_SOCKET));
            }
            $anchored = true;
            $this->port = -1;
            $this->socketPath = substr($elm->arg, 6);
            $this->host = ":unix:" . $this->socketPath;
        }
        else {
            // TCP or UDP port
            if(StringUtil::startsWith($portName, ":tcp:")) {
                // tcp server socket
                $anchored = true;
                $hostPort = substr($elm->arg, 5);
            }
            elseif(StringUtil::startsWith($portName, ":udp:")) {
                // udp server socket
                $anchored = false;
                $hostPort = substr($elm->arg, 5);
            }
            else {
                // default: tcp server socket
                $anchored = true;
                $hostPort = $elm->arg;
            }
            $idx = strpos($hostPort, ':');

            try {
                if ($idx === false) {
                    $this->host = null;
                    $this->port = intval($hostPort);
                }
                else {
                    $this->host = substr($hostPort, 0, $idx);
                    $this->port = intval(substr($hostPort, $idx + 1));
                }
            }
            catch(\Exception $e) {
                throw new ConfigException($elm->fileName, $elm->lineNo, BayMessage::get(Symbol::CFG_INVALID_PORT_NAME, $elm->arg));
            }
        }

        // TCP/UDP support check
        if($anchored) {
            if (!$this->supportAnchored())
                throw new ConfigException($elm->fileName, $elm->lineNo, BayMessage::get(Symbol::CFG_TCP_NOT_SUPPORTED));
        }
        else {
            if (!$this->supportUnanchored())
                throw new ConfigException($elm->fileName, $elm->lineNo, BayMessage::get(Symbol::CFG_UDP_NOT_SUPPORTED));
        }

    }

    ///////////////////////////////////////////////////////////////////////
    // abstract methods
    ///////////////////////////////////////////////////////////////////////

    abstract protected function supportAnchored() : bool;
    abstract protected function supportUnanchored() : bool;


    //////////////////////////////////////////////////////
    // Implements DockerBase
    //////////////////////////////////////////////////////

    public function initDocker($dkr) : bool
    {
        if ($dkr instanceof Permission)
            $this->permissionList[] = $dkr;
        elseif ($dkr instanceof City)
            $this->cities->add($dkr);
        elseif ($dkr instanceof Secure)
            $this->secureDocker = $dkr;
        else
            return parent::init_docker($dkr);

        return true;
    }

    public function initKeyVal($kv) : bool
    {
        $key = strtolower($kv->key);
        switch($key) {
            case "timeout":
                $this->timeoutSec = intval($kv->value);
                break;

            case "addheader":
                $idx = strpos($kv->value, ':');
                if ($idx === false) {
                    throw new ConfigException($kv->fileName, $kv->lineNo, BayMessage::get(Symbol::CFG_INVALID_PARAMETER_VALUE, $kv->value));
                }
                $name = trim(substr($kv->value, 0, idx));
                $value = trim(substr($kv->value, idx + 1));
                $this->additionalHeaders[] = [$name, $value];

            case "nonblockingtimeout":
                $this->nonBlockingTimeoutMillis = intval($kv->value);
                break;

            default:
                return parent::initKeyVal($kv);
        }
        return true;
    }


    //////////////////////////////////////////////////////
    // implements Port
    //////////////////////////////////////////////////////

    public function host(): ?string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function socketPath(): ?string
    {
        return $this->socketPath;
    }

    public function address(): array
    {
        if ($this->socketPath) {
            #  Unix domain socket
            return [$this->socketPath, null];
        }
        elseif ($this->host == null) {
            return ["0.0.0.0", $this->port];
        }
        else {
            return [$this->host, $this->port];
        }
    }

    public function anchored(): bool
    {
        return $this->anchored;
    }

    public final function secure() : bool
    {
        return $this->secureDocker !== null;
    }

    public function timeoutSec() : int
    {
        return $this->timeoutSec;
    }

    public function additionalHeaders() : array
    {
        return $this->additionalHeaders;
    }

    public final function findCity(string $name) : ?City
    {
        return $this->cities->findCity($name);
    }

    public final function onConnected(int $agtId, Rudder $rd) : void
    {
        $this->checkAdmitted($rd);

        $sip = self::getShipStore($agtId)->rent();
        $agt = GrandAgent::get($agtId);

        if ($this->secure()) {
            $tp = $this->secureDocker->newTransporter($agtId, $sip, IOUtil::getSockRecvBufSize($rd->key()));
        }
        else {
            $tp = new PlainTransporter(
                        $agt->netMultiplexer,
                        $sip,
                        false,
                        IOUtil::getSockRecvBufSize($rd->key()),
                false);
        }

        $protoHnd = PortBase::getProtocolHandlerStore($this->protocol(), $agtId)->rent();
        $sip->initInbound($rd, $agtId, $tp, $this, $protoHnd);

        $st = new RudderState($rd, $tp);
        $agt->netMultiplexer->addRudderState($rd, $st);
        $agt->netMultiplexer->reqRead($rd);
    }


    //////////////////////////////////////////////////////
    // Other methods
    //////////////////////////////////////////////////////

    public final function returnProtocolHandler(int $agtId, ProtocolHandler $handler) : void
    {
        BayLog::debug("agt#%d Return protocol handler: %s", $agtId, $handler);
        $this->getProtocolHandlerStore($handler->protocol(), $agtId)->Return($handler);
    }

    public function returnShip(InboundShip $sip) : void
    {
        BayLog::debug("%s end (return ships)", $sip);
        $this->getShipStore($sip->agentId)->Return($sip);
    }

    //////////////////////////////////////////////////////
    // Private methods
    //////////////////////////////////////////////////////
    public final function checkAdmitted(Rudder $rd) : void
    {
        foreach($this->permissionList as $permDkr)
            $permDkr->socketAdmitted($rd);
    }

    public static function getShipStore(int $agtId) : InboundShipStore
    {
        return InboundShipStore::getStore($agtId);
    }

    public static function getProtocolHandlerStore(string $proto, int $agtId) : ProtocolHandlerStore
    {
        return ProtocolHandlerStore::getStore($proto, True, $agtId);
    }

    public function sslCtx()
    {
        BayLog::debug("SSL Context: %s", $this->secureDocker->sslctx);
        return $this->secureDocker->sslctx;
    }
}
