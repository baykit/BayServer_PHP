<?php

namespace baykit\bayserver\protocol;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\LifecycleListener;
use baykit\bayserver\agent\transporter\Transporter;
use baykit\bayserver\BayLog;
use baykit\bayserver\Sink;
use baykit\bayserver\util\DataConsumeListener;
use baykit\bayserver\util\ObjectStore;
use baykit\bayserver\util\Reusable;
use baykit\bayserver\util\StringUtil;

class PacketStore_AgentListener implements LifecycleListener
{

    public function add(int $agtId): void
    {
        foreach (PacketStore::$protoMap as $proto => $ifo) {
            $ifo->addAgent($agtId);
        }
    }

    public function remove(int $agtId): void
    {
        foreach (PacketStore::$protoMap as $proto => $ifo) {
            $ifo->removeAgent($agtId);
        }
    }
}

class ProtocolInfo
{
    public $protocol;
    public $packet_factory;

    /** Agent ID => PacketStore */
    public $stores;

    public function __construct($protocol, $packet_factory)
    {
        $this->protocol = $protocol;
        $this->packet_factory = $packet_factory;
        $this->stores = [];
    }

    public function addAgent(int $agt_id): void
    {
        $store = new PacketStore($this->protocol, $this->packet_factory);
        $this->stores[$agt_id] = $store;
    }

    public function removeAgent(int $agt_id): void
    {
        unset($this->stores[$agt_id]);
    }
}

class PacketStore implements Reusable
{

    public static $protoMap = [];

    public $protocol;
    public $factory;
    public $storeMap = [];

    public function __construct($protocol, $factory)
    {
        $this->protocol = $protocol;
        $this->factory = $factory;
    }


    /////////////////////////////////////////////////////////////////////////////////
    // Implements Reusable
    /////////////////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        foreach ($this->storeMap as $proto => $store) {
            $store->reset();
        }
    }

    public function rent($typ) : Packet
    {
        if(!array_key_exists($typ, $this->storeMap)) {
            $store = new ObjectStore(function () use ($typ) {
                return $this->factory->createPacket($typ);
            });
            $this->storeMap[$typ] = $store;
        }
        else
            $store = $this->storeMap[$typ];

        return $store->rent();
    }

    public function Return($pkt) : void
    {
        $store = $this->storeMap[$pkt->type];
        $store->Return($pkt);
    }

    public function printUsage(int $indent) : void
    {
        BayLog::info("%sPacketStore(%s) usage nTypes=%d", StringUtil::indent($indent), $this->protocol, count($this->storeMap));
        foreach ($this->storeMap as $type => $store) {
            BayLog::info("%sType: %s", StringUtil::indent($indent + 1), $type);
            $this->storeMap[$type]->printUsage($indent + 2);
        }
    }


    /////////////////////////////////////////////////////////////////////////////////
    // Class methods
    /////////////////////////////////////////////////////////////////////////////////

    public static function init() : void
    {
        GrandAgent::addLifecycleListener(new PacketStore_AgentListener());
    }

    public static function getStore(string $protocol, int $agentId) : PacketStore
    {
        return self::$protoMap[$protocol]->stores[$agentId];
    }

    public static function  registerProtocol(string $protocol, PacketFactory $factory) : void
    {
        if(!array_key_exists($protocol, self::$protoMap))
        {
            self::$protoMap[$protocol] = new ProtocolInfo($protocol, $factory);
        }
    }

    public static function getStores(int $agentId) : array
    {
        $store_list = [];
        foreach (PacketStore::$protoMap as $proto => $ifo) {
            $store_list[] = $ifo->stores[$agentId];
        }

        return $store_list;
    }


}