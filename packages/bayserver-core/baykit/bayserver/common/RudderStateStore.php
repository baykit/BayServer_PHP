<?php

namespace baykit\bayserver\common;


use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\LifecycleListener;
use baykit\bayserver\BayLog;
use baykit\bayserver\util\ObjectStore;
use baykit\bayserver\util\StringUtil;

class RudderStateStore_AgentListener implements LifecycleListener
{
    public function add(int $agentId) : void
    {
        RudderStateStore::$stores[$agentId] = new RudderStateStore();
    }

    public function remove(int $agentId) : void
    {
        RudderStateStore::$stores[$agentId] = null;
    }
}

class RudderStateStore extends ObjectStore
{
    /** @var RudderStateStore[]  agent_id => RudderStateStore */
    public static array $stores = [];

    public function __construct()
    {
        parent::__construct(function () { return new RudderState(); });
    }

    public function printUsage(int $indent) : void
    {
        BayLog::info("%sRudderStateStore Usage:", StringUtil::indent($indent));
        parent::printUsage($indent + 1);
    }

    public static function init() : void
    {
        GrandAgent::addLifecycleListener(new RudderStateStore_AgentListener());
    }

    public static function getStore(int $agentId) : RudderStateStore
    {
        return self::$stores[$agentId];
    }
}
