<?php
namespace baykit\bayserver\docker\base;


use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\LifecycleListener;
use baykit\bayserver\BayLog;
use baykit\bayserver\util\ObjectStore;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\ArrayUtil;

class InboundShipStore_AgentListener implements LifecycleListener
{
    public function add(int $agtId): void
    {
        InboundShipStore::$stores[$agtId] = new InboundShipStore();
    }

    public function remove(int $agtId): void
    {
        unset(InboundShipStore::$stores[$agtId]);
    }
}

class InboundShipStore extends ObjectStore
{
    public static $stores = [];

    public function __construct()
    {
        parent::__construct(function() { return new InboundShip(); });
    }

    /**
     *  print memory usage
     */
    public function printUsage($indent) : void
    {
        BayLog::info("%sInboundShipStore Usage:", StringUtil::indent($indent));
        parent::printUsage($indent + 1);
    }

    public static function init() : void
    {
        GrandAgent::addLifecycleListener(new InboundShipStore_AgentListener());
    }

    public static function getStore(int $agt_id) : InboundShipStore
    {
        return InboundShipStore::$stores[$agt_id];
    }
}