<?php

namespace baykit\bayserver\docker\base;


use baykit\bayserver\agent\transporter\PlainTransporter;
use baykit\bayserver\agent\transporter\Transporter;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\ConfigException;
use baykit\bayserver\docker\City;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\docker\Permission;
use baykit\bayserver\docker\Port;
use baykit\bayserver\docker\base\DockerBaset;
use baykit\bayserver\docker\Secure;
use baykit\bayserver\protocol\ProtocolHandlerStore;
use baykit\bayserver\Symbol;
use baykit\bayserver\util\Cities;
use baykit\bayserver\util\IOUtil;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\SysUtil;

abstract class PortBase extends DockerBase implements Port {

    const DEFAULT_NON_BLOCKING_TIMEOUT = 100;

    public $permissionList = [];
    public $timeoutSec = -1;
    public $host = null;
    public $port = null;
    public $socketPath = null;
    public $secureDocker = null;
    public $anchored = true;
    public $additionalHeaders = [];
    public $cities;
    public $nonBlockingTimeoutMillis = PortBase::DEFAULT_NON_BLOCKING_TIMEOUT;

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

    public final function checkAdmitted($skt) : void
    {
        foreach($this->permissionList as $permDkr)
            $permDkr->socketAdmitted($skt);
    }

    public function additionalHeaders() : array
    {
        return $this->additionalHeaders;
    }

    public final function findCity(string $name) : ?City
    {
        return $this->cities->findCity($name);
    }

    public final function newTransporter($agt, $skt) : Transporter
    {
        $sip = self::getShipStore($agt)->rent();
        if ($this->secure())
            $tp = $this->secureDocker->createTransporter(IOUtil::getSockRecvBufSize($skt));
        else
            $tp = new PlainTransporter(true, IOUtil::getSockRecvBufSize($skt));

        $protoHnd = PortBase::getProtocolHandlerStore($this->protocol(), $agt)->rent();
        $sip->initInbound($skt, $agt, $tp, $this, $protoHnd);
        $tp->init($agt->nonBlockingHandler, $skt, new InboundDataListener($sip));
        return $tp;
    }


    //////////////////////////////////////////////////////
    // Other methods
    //////////////////////////////////////////////////////

    public final function returnProtocolHandler($agt, $handler) : void
    {
        BayLog::debug("%s Return protocol handler: %s", $agt, $handler);
        $this->getProtocolHandlerStore($handler->protocol(), $agt)->Return($handler);
    }

    public function returnShip($sip) : void
    {
        BayLog::debug("%s end (return ships)", $sip);
        $this->getShipStore($sip->agent)->Return($sip);
    }

    public static function getShipStore($agt)
    {
        return InboundShipStore::getStore($agt->agentId);
    }

    public static function getProtocolHandlerStore($proto, $agt)
    {
        return ProtocolHandlerStore::getStore($proto, True, $agt->agentId);
    }

    public function sslCtx()
    {
        return $this->secureDocker->sslctx;
    }
}
