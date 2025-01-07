<?php
namespace baykit\bayserver\docker\base;



use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\LifecycleListener;
use baykit\bayserver\BayLog;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\bcf\BcfKeyVal;
use baykit\bayserver\common\RudderState;
use baykit\bayserver\common\Transporter;
use baykit\bayserver\common\WarpShipStore;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\docker\Warp;
use baykit\bayserver\HttpException;
use baykit\bayserver\protocol\ProtocolHandlerStore;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\rudder\SocketRudder;
use baykit\bayserver\rudder\StreamRudder;
use baykit\bayserver\ship\Ship;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\StringUtil;


class WarpDocker_AgentListener implements LifecycleListener
{

    private WarpBase $warpBase;

    public function __construct(WarpBase $base)
    {
        $this->warpBase = $base;
    }

    public function add(int $agtId): void
    {
        $this->warpBase->stores[$agtId] = new WarpShipStore($this->warpBase->maxShips);
    }

    public function remove(int $agtId): void
    {
        unset($this->warpBase->stores[$agtId]);
    }
}


abstract class WarpBase extends ClubBase implements Warp
{
    public string $host;
    public int $port = -1;
    public string $warpBase;
    public int $maxShips = -1;
    public int $timeoutSec = -1; // -1 means "Use harbor.socketTimeoutSec"

    private $tourList = [];

    /** Agent ID => WarpShipStore */
    public $stores = [];

    //////////////////////////////////////////
    // Abstract methods
    //////////////////////////////////////////
    public abstract function secure() : bool;
    protected abstract function protocol(): string;
    protected abstract function newTransporter(GrandAgent $agent, Rudder $rd, Ship $sip): Transporter;

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
        $agt = GrandAgent::get($tur->ship->agentId);
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
                stream_set_blocking($ch, false);

                $rd = new StreamRudder($ch);
                $tp = $this->newTransporter($agt, $rd, $wsip);
                $protoHnd = ProtocolHandlerStore::getStore($this->protocol(), false, $agt->agentId)->rent();
                $wsip->initWarp($rd, $agt->agentId, $tp, $this, $protoHnd);

                BayLog::debug("%s init warp ship: addr=%s ch=%s", $wsip, $address, $ch);
                $needConnect = true;
            }

            $this->tourList[] = $tur;

            $wsip->startWarpTour($tur);

            if($needConnect) {
                $agt->netMultiplexer->addRudderState($wsip->rudder, new RudderState($wsip->rudder, $tp));
                $agt->netMultiplexer->getTransporter($wsip->rudder)->reqConnect($wsip->rudder, $address);
            }
         }
        catch(IOException $e) {
            BayLog::error($e);
            throw new HttpException(HttpStatus::INTERNAL_SERVER_ERROR, $e);
        }
    }

    //////////////////////////////////////////
    // Implements Warp
    //////////////////////////////////////////

    public function host() : string
    {
        return $this->host;
    }

    public function port() : int
    {
        return $this->port;
    }

    public function warpBase() : string
    {
        return $this->warpBase;
    }

    public function timeoutSec() : int
    {
        return $this->timeoutSec;
    }

    public function keep(Ship $warpShip) : void
    {
        BayLog::debug("%s keep warp ship: %s", $this, $warpShip);
        $this->getShipStore($warpShip->agentId)->keep($warpShip);
    }

    public function onEndShip(Ship $warpShip) : void
    {
        BayLog::debug("%s Return protocol handler: ", $warpShip);
        $this->getProtocolHandlerStore($warpShip->agentId)->Return($warpShip->protocolHandler);
        BayLog::debug("%s return warp ship: %s", $this, $warpShip);
        $this->getShipStore($warpShip->agentId)->Return($warpShip);
    }

    //////////////////////////////////////////
    // Other methods
    //////////////////////////////////////////

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