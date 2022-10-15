<?php
namespace baykit\bayserver\protocol;


use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\LifecycleListener;
use baykit\bayserver\BayLog;
use baykit\bayserver\util\ObjectFactory;
use baykit\bayserver\util\ObjectStore;
use baykit\bayserver\util\StringUtil;

class ProtocolHandleStore_AgentListener implements LifecycleListener
{

    public function add(int $agtId): void
    {
        foreach (ProtocolHandlerStore::$protoMap as $proto => $ifo) {
            $ifo->addAgent($agtId);
        }
    }

    public function remove(int $agtId): void
    {
        foreach (ProtocolHandlerStore::$protoMap as $proto => $ifo) {
            $ifo->removeAgent($agtId);
        }
    }
}

class ProtocolHandlerStore_ProtocolInfo
{
    public $protocol;
    public $serverMode;
    public $protocolHandlerFactory;

    /** Agent ID => ProtocolHandlerStore */
    public $stores = [];


    public function __construct($protocol, $serverMode, $protocolHandlerFactory)
    {
        $this->protocol = $protocol;
        $this->serverMode = $serverMode;
        $this->protocolHandlerFactory = $protocolHandlerFactory;
    }

    public function addAgent(int $agtId) :void
    {
        $store = PacketStore::getStore($this->protocol, $agtId);
        $this->stores[$agtId] =  new ProtocolHandlerStore($this->protocol, $this->serverMode, $this->protocolHandlerFactory, $store);
    }

    public function removeAgent(int $agtId) : void
    {
        unset($this->stores[$agtId]);
    }

}

/**
 * Protocol handler pool
 */
class ProtocolHandlerStore extends ObjectStore
{
    public static $protoMap = [];
    public $protocol;
    public $serverMode;


    public function __construct($protocol, $serverMode, $protoHndFactory, $pktStore)
    {
        parent::__construct();
        $this->protocol = $protocol;
        $this->serverMode = $serverMode;
        $this->factory = function () use ($pktStore, $protoHndFactory) {
            return $protoHndFactory->createProtocolHandler($pktStore);
        };
    }

    public function printUsage(int $indent) : void
    {
        BayLog::info("%sProtocolHandlerStore(%s%s) Usage:", StringUtil::indent($indent), $this->protocol, $this->serverMode ? "s" : "c");
        parent::print_usage($indent + 1);
    }

    public static function init() : void
    {
        GrandAgent::addLifecycleListener(new ProtocolHandleStore_AgentListener());
    }

    public static function getStore(string $protocol, bool $svrMode, int $agtId) : ProtocolHandlerStore
    {
        return ProtocolHandlerStore::$protoMap[ProtocolHandlerStore::constructProtocol($protocol, $svrMode)]->stores[$agtId];
    }

    public static function getStores(int $agt_id) : array
    {
        $store_list = [];
        foreach (ProtocolHandlerStore::$protoMap as $proto => $ifo)
            $store_list[] = $ifo->stores[$agt_id];
        return $store_list;
    }

    public static function registerProtocol(string $protocol, bool $svrMode, ProtocolHandlerFactory $protoHndFactory)
    {
        if (!array_key_exists(ProtocolHandlerStore::constructProtocol($protocol, $svrMode), ProtocolHandlerStore::$protoMap)) {
           ProtocolHandlerStore::$protoMap[ProtocolHandlerStore::constructProtocol($protocol, $svrMode)] =
               new ProtocolHandlerStore_ProtocolInfo($protocol, $svrMode, $protoHndFactory);
        }
    }

    public static function constructProtocol(string $protocol, bool $svrMode) : string
    {
        if($svrMode)
            return $protocol . "-s";
        else
            return $protocol . "-c";
    }
}