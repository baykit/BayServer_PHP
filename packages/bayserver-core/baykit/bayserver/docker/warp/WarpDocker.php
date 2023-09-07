<?php
namespace baykit\bayserver\docker\warp;



use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\LifecycleListener;
use baykit\bayserver\BayLog;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\bcf\BcfKeyVal;
use baykit\bayserver\docker\base\ClubBase;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\HttpException;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\protocol\ProtocolHandlerStore;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\StringUtil;


class WarpDocker_AgentListener implements LifecycleListener
{

    private $warpDocker;

    public function __construct(Docker $dkr)
    {
        $this->warpDocker = $dkr;
    }

    public function add(int $agtId): void
    {
        $this->warpDocker->stores[$agtId] = new WarpShipStore($this->warpDocker->maxShips);
    }

    public function remove(int $agtId): void
    {
        unset($this->warpDocker->stores[$agtId]);
    }
}


abstract class WarpDocker extends ClubBase
{
    public $scheme;
    public $host;
    public $port = -1;
    public $warpBase;
    public $maxShips = -1;
    private $hostAddr;
    public $timeoutSec = -1; // -1 means "Use harbor.socketTimeoutSec"

    private $tourList = [];

    /** Agent ID => WarpShipStore */
    public $stores = [];

    //////////////////////////////////////////
    // Abstract methods
    //////////////////////////////////////////
    public abstract function secure() : bool;
    protected abstract function protocol(): string;
    protected abstract function newTransporter(GrandAgent $agent, $ch);

    //////////////////////////////////////////
    // Implements DockerBase
    //////////////////////////////////////////
    public function init(BcfElement $elm, ?Docker $parent): void
    {
        parent::init($elm, $parent);

        if(StringUtil::isEmpty($this->warpBase))
            $this->warpBase = "/";

        if(StringUtil::isSet($this->host) && StringUtil::startsWith($this->host, ":unix:")) {
            $this->host = substr($this->host, 6);
            $this->port = null;
        }
        else {
            if($this->port <= 0)
                $this->port = 80;
        }

        GrandAgent::addLifecycleListener(new WarpDocker_AgentListener($this));
    }

    public function initKeyVal(BcfKeyVal $kv): bool
    {
        switch (strtolower($kv->key)) {
            case "destcity":
                $this->host = $kv->value;
                break;

            case "destport":
                $this->port= intval($kv->value);
                break;

            case "desttown":
                $this->warpBase = $kv->value;
                if (!StringUtil::endsWith($this->warpBase, "/"))
                    $this->warpBase .= "/";
                break;

            case "maxships":
                $this->maxShips = intval($kv->value);
                break;

            case "timeout":
                $this->timeoutSec = intval($kv->value);
                break;

            default:
                return parent::initKeyVal($kv);

        }
        return true;
    }


    //////////////////////////////////////////
    // Implements Club
    //////////////////////////////////////////
    public function arrive(Tour $tur)
    {
        $agt = $tur->ship->agent;
        $sto = $this->getShipStore($agt->agentId);

        $wsip = $sto->rent();
        if($wsip == null) {
            throw new HttpException(HttpStatus::SERVICE_UNAVAILABLE, "WarpDocker busy");
        }

        try {
            BayLog::trace("%s got from store", $wsip);
            $needConnect = false;
            $tp = null;
            if (!$wsip->initialized) {
                if($this->port == null) {
                    // Unix domain socket
                    $address ="unix://{$this->host}";
                }
                else {
                    $address = "tcp://{$this->host}:{$this->port}";
                }

                // Create socket
                $ch = stream_socket_client($address, $error_code, $error_message, null, STREAM_CLIENT_ASYNC_CONNECT);
                if($ch === false) {
                    throw new IOException("Connect failed: {$address}: {$error_message}({$error_code})");
                }

                $tp = $this->newTransporter($agt, $ch);
                $protoHnd = ProtocolHandlerStore::getStore($this->protocol(), false, $agt->agentId)->rent();
                $wsip->initWarp($ch, $agt, $tp, $this, $protoHnd);
                $tp->init($agt->nonBlockingHandler, $ch, new WarpDataListener($wsip));
                BayLog::debug("%s init warp ship: %s", $wsip, $address);
                $needConnect = true;
            }

            $this->tourList[] = $tur;

            $wsip->startWarpTour($tur);

            if($needConnect) {
                $agt->nonBlockingHandler->addChannelListener($wsip->socket, $tp);
                $agt->nonBlockingHandler->askToConnect($wsip->socket, $this->hostAddr);
            }
         }
        catch(IOException $e) {
            BayLog::error($e);
            throw new HttpException(HttpStatus::INTERNAL_SERVER_ERROR, $e);
        }
    }

    //////////////////////////////////////////
    // Other methods
    //////////////////////////////////////////
    public function keepShip(WarpShip $wsip) : void
    {
        BayLog::debug("%s keep warp ship: %s", $this, $wsip);
        $this->getShipStore($wsip->agent->agentId)->keep($wsip);
    }

    public function returnShip(WarpShip $wsip) : void
    {
        BayLog::debug("%s return warp ship: %s", $this, $wsip);
        $this->getShipStore($wsip->agent->agentId)->Return($wsip);
    }

    public function returnProtocolHandler(GrandAgent $agt, ProtocolHandler $protoHnd) : void
    {
        BayLog::debug("%s Return protocol handler: ", $protoHnd);
        $this->getProtocolHandlerStore($agt->agentId)->Return($protoHnd);
    }

    public function getShipStore(int $agtId) : WarpShipStore
    {
        return $this->stores[$agtId];
    }

    //////////////////////////////////////////
    // Private methods
    //////////////////////////////////////////

    private function getProtocolHandlerStore(int $agtId) : ProtocolHandlerStore
    {
        return ProtocolHandlerStore::getStore($this->protocol(), false, $agtId);
    }
}