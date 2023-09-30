<?php
namespace baykit\bayserver\tour;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\LifecycleListener;
use baykit\bayserver\BayLog;
use baykit\bayserver\Sink;
use baykit\bayserver\util\StringUtil;

class TourStore_AgentListener implements LifecycleListener
{
    public function add(int $agtId): void
    {
        TourStore::$stores[$agtId] = new TourStore();
    }

    public function remove(int $agtId): void
    {
        unset(TourStore::$stores[$agtId]);
    }
}


class TourStore {

    public static $stores = [];
    public static $maxCount;

    const MAX_TOURS = 128;

    public $freeTours = [];
    public $activeTourMap = [];


    public function get(int $key) : ?Tour
    {
        if(array_key_exists($key, $this->activeTourMap))
            return $this->activeTourMap[$key];
        else
            return null;
    }

    public function rent(int $key, bool $force) : ?Tour
    {
        $tur = $this->get($key);
        if($tur !== null)
            throw new Sink("Tour is active: " . $tur);

        if (count($this->freeTours) > 0) {
            //BayLog.debug("rent: key=%d from free tours", key);
            $tur = array_pop($this->freeTours);
        } else {
            //BayLog.debug("rent: key=%d Active tour count: %d", key, activeTourMap.size());
            if (!$force && (count($this->activeTourMap) >= self::$maxCount)) {
                BayLog::warn("Max tour count reached");
                return null;
            } else {
                $tur = new Tour();
            }
        }

        $this->activeTourMap[$key] = $tur;
        return $tur;
    }

    public function Return(int $key) : void
    {
        if(!array_key_exists($key, $this->activeTourMap)) {
            throw new Sink("Tour is not active key=: " . key);
        }
        $tur = $this->activeTourMap[$key];
        unset($this->activeTourMap[$key]);
        //BayLog.debug("return: key=%d Active tour count: after=%d", key, activeTourMap.size());
        $tur->reset();
        $this->freeTours[] = $tur;
    }

    /**
     * print memory usage
     */
    public function printUsage(int $indent) : void
    {
        BayLog::info("%sTour store usage:", StringUtil::indent($indent));
        BayLog::info("%sfreeList: %d", StringUtil::indent($indent+1), count($this->freeTours));
        BayLog::info("%sactiveList: %d", StringUtil::indent($indent+1), count($this->activeTourMap));
        if(BayLog::isDebugMode()) {
            foreach ($this->activeTourMap as $key => $obj) {
                BayLog::debug("%s%s", StringUtil::indent($indent+1), $obj);
            }
        }
    }

    public static function init(int $maxTourCount) : void
    {
        self::$maxCount = $maxTourCount;
        GrandAgent::addLifecycleListener(new TourStore_AgentListener());
    }

    public static function getStore(int $agtId) : TourStore
    {
        return self::$stores[$agtId];
    }

}
