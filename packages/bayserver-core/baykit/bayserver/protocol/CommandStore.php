<?php

namespace baykit\bayserver\protocol;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\LifecycleListener;
use baykit\bayserver\BayLog;
use baykit\bayserver\util\ObjectStore;
use baykit\bayserver\util\Reusable;
use baykit\bayserver\util\StringUtil;

class CommandStore_AgentListener implements LifecycleListener
{
    public function add(int $agtId): void
    {
        foreach (CommandStore::$protoMap as $proto => $ifo) {
            $ifo->addAgent($agtId);
        }
    }

    public function remove(int $agtId): void
    {
        foreach (CommandStore::$protoMap as $proto => $ifo) {
            $ifo->removeAgent($agtId);
        }
    }
}

class CommandProtocolInfo
{
    public string $protocol;
    public CommandFactory $commandFactory;

    /** Agent ID => CommandStore */
    public array $stores = [];

    public function __construct(string $protocol, CommandFactory $cmdFactory)
    {
        $this->protocol = $protocol;
        $this->commandFactory = $cmdFactory;
    }

    public function addAgent(int $agt_id): void
    {
        $store = new CommandStore($this->protocol, $this->commandFactory);
        $this->stores[$agt_id] = $store;
    }

    public function removeAgent(int $agt_id): void
    {
        unset($this->stores[$agt_id]);
    }
}

/**
 * Per-agent Command object pool, indexed by command type.
 *
 * Mirrors PacketStore but for Command objects: each protocol registers
 * a CommandFactory keyed on type int, and rent($type) hands back a
 * recycled Command (or creates one via the factory). Return($cmd) puts
 * it back in the free list after the consumer (UnPacker / Handler) is
 * done with it.
 *
 * Process-per-agent in PHP means there is no contention, but the
 * pooling discipline still keeps allocation churn down on hot paths
 * (post() + dispatch) and matches the Java parity port.
 */
class CommandStore implements Reusable
{
    public static array $protoMap = [];

    public string $protocol;
    public CommandFactory $factory;
    public array $storeMap = [];

    public function __construct(string $protocol, CommandFactory $factory)
    {
        $this->protocol = $protocol;
        $this->factory = $factory;
    }

    /////////////////////////////////////////////////////////////////////////////////
    // Implements Reusable
    /////////////////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        foreach ($this->storeMap as $type => $store) {
            $store->reset();
        }
    }

    public function rent(int $typ) : Command
    {
        if (!array_key_exists($typ, $this->storeMap)) {
            $factory = $this->factory;
            $store = new ObjectStore(function () use ($factory, $typ) {
                return $factory->createCommand($typ);
            });
            $this->storeMap[$typ] = $store;
        }
        else {
            $store = $this->storeMap[$typ];
        }
        return $store->rent();
    }

    public function Return(Command $cmd) : void
    {
        $store = $this->storeMap[$cmd->type];
        $store->Return($cmd);
    }

    public function printUsage(int $indent) : void
    {
        BayLog::info("%sCommandStore(%s) usage nTypes=%d", StringUtil::indent($indent), $this->protocol, count($this->storeMap));
        foreach ($this->storeMap as $type => $store) {
            BayLog::info("%sType: %s", StringUtil::indent($indent + 1), $type);
            $store->printUsage($indent + 2);
        }
    }

    /////////////////////////////////////////////////////////////////////////////////
    // Class methods
    /////////////////////////////////////////////////////////////////////////////////

    public static function init() : void
    {
        GrandAgent::addLifecycleListener(new CommandStore_AgentListener());
    }

    public static function getStore(string $protocol, int $agentId) : CommandStore
    {
        return self::$protoMap[$protocol]->stores[$agentId];
    }

    public static function registerProtocol(string $protocol, CommandFactory $cmdFactory) : void
    {
        if (!array_key_exists($protocol, self::$protoMap)) {
            self::$protoMap[$protocol] = new CommandProtocolInfo($protocol, $cmdFactory);
        }
    }

    public static function getStores(int $agentId) : array
    {
        $store_list = [];
        foreach (CommandStore::$protoMap as $proto => $ifo) {
            $store_list[] = $ifo->stores[$agentId];
        }
        return $store_list;
    }
}
