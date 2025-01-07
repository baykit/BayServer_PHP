<?php

namespace baykit\bayserver;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\LifecycleListener;
use baykit\bayserver\common\InboundShipStore;
use baykit\bayserver\docker\base\WarpBase;
use baykit\bayserver\docker\City;
use baykit\bayserver\docker\Port;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandlerStore;
use baykit\bayserver\tour\TourStore;
use baykit\bayserver\util\ArrayUtil;
use baykit\bayserver\util\StringUtil;


class MemUsage_AgentListener implements LifecycleListener
{

    public function add(int $agtId): void
    {
        MemUsage::$memUsages[$agtId] = new MemUsage($agtId);
    }

    public function remove(int $agtId): void
    {
        ArrayUtil::remove($agtId, MemUsage::$memUsages);
    }
}


class MemUsage
{
    /** Agent ID => MemUsage */
    public static $memUsages = [];

    private $agentId;

    /**
     * @param $agentId
     */
    public function __construct($agentId)
    {
        $this->agentId = $agentId;
    }

    public function printUsage(int $indent): void
    {
        InboundShipStore::getStore($this->agentId)->printUsage($indent+1);
        foreach(ProtocolHandlerStore::getStores($this->agentId) as $store) {
            $store->printUsage($indent+1);
        }
        foreach(PacketStore::getStores($this->agentId) as $store) {
            $store->printUsage($indent + 1);
        }
        TourStore::getStore($this->agentId)->printUsage($indent+1);
        foreach(BayServer::$cities->cities() as $city) {
            $this->printCityUsage(null, $city, $indent);
        }
        foreach(BayServer::$portDockerList as $port) {
            foreach ($port->cities->cities() as $city) {
                $this->printCityUsage($port, $city, $indent);
            }
        }
    }

    public static function init(): void
    {
        GrandAgent::addLifecycleListener(new MemUsage_AgentListener());
    }

    public static function get(int $agentId): MemUsage
    {
        return MemUsage::$memUsages[$agentId];
    }

    function printCityUsage(?Port $port, City $city, int $indent): void
    {
        $pname = ($port == null) ? "" : "@" . $port;
        foreach($city->clubs() as $club) {
            if ($club instanceof WarpBase) {
                BayLog::info("%sClub(%s%s) Usage:", StringUtil::indent($indent), $club, $pname);
                $club->getShipStore($this->agentId)->printUsage($indent+1);
            }
        }
        foreach($city->towns() as $town) {
            foreach($town->clubs as $club) {
                if ($club instanceof WarpBase) {
                    BayLog::info("%sClub(%s%s) Usage:", StringUtil::indent($indent), $club, $pname);
                    $club->getShipStore($this->agentId)->printUsage($indent+1);
                }
            }
        }
    }


}