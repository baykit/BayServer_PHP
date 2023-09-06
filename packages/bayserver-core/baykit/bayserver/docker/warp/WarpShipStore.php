<?php
namespace baykit\bayserver\docker\warp;


use baykit\bayserver\BayLog;
use baykit\bayserver\Sink;
use baykit\bayserver\util\ArrayUtil;
use baykit\bayserver\util\ObjectStore;
use baykit\bayserver\util\StringUtil;

class WarpShipStore extends ObjectStore
{
    private $keepList = [];
    private $busyList = [];

    private $maxShips;

    public function __construct(int $maxShips)
    {
        parent::__construct();
        $this->maxShips = $maxShips;
        $this->factory = function () { return new WarpShip(); };
    }

    public function rent() : ?WarpShip
    {
        //BayLog::debug("rent: before freeList=%s busyList=%s", ArrayUtil::toString($this->keepList), ArrayUtil::toString($this->busyList));
        if($this->maxShips > 0 && $this->count() >= $this->maxShips)
            return null;

        $wsip = null;
        if(count($this->keepList) == 0) {
            BayLog::trace("rent from Object Store");
            $wsip = parent::rent();
            if ($wsip == null)
                return null;
        }
        else {
            //BayLog::trace("rent from freeList: %s", $this->keepList);
            $wsip = $this->keepList[0];
            ArrayUtil::removeByIndex(0, $this->keepList);
        }

        if($wsip == null)
            throw new Sink("BUG! ship is null");
        if($wsip->postman != null && $wsip->postman->isZombie())
            throw new Sink("BUG! channel is zombie: " . $wsip);
        $this->busyList[] = $wsip;

        //BayLog::debug("rent: after freeList=%s busyList=%s", ArrayUtil::toString($this->keepList), ArrayUtil::toString($this->busyList));
        return $wsip;
    }

    /**
     * Keep ship which connection is alive
     * @param wsip
     */
    public function keep(WarpShip $wsip) : void
    {
        //BayLog::debug("keep: before freeList=%s busyList=%s", ArrayUtil::toString($this->keepList), ArrayUtil::toString($this->busyList));
        if(!in_array($wsip, $this->busyList))
            BayLog::error("BUG: %s not in busy list",$wsip);
        else
            ArrayUtil::remove($wsip, $this->busyList);
        $this->keepList[] = $wsip;
        //BayLog::debug("keep: after freeList=%s busyList=%s", ArrayUtil::toString($this->keepList), ArrayUtil::toString($this->busyList));
    }

    /**
     * Return ship which connection is closed
     * @param wsip
     */
    public function Return(object $wsip, $reuse=true) : void
    {
        //BayLog::debug("Return: before freeList=%s busyList=%s", ArrayUtil::toString($this->keepList), ArrayUtil::toString($this->busyList));
        $removedFromFree = false;
        if(in_array($wsip, $this->keepList)) {
            ArrayUtil::remove($wsip, $this->keepList);
            $removedFromFree = true;
        }

        $removedFromBusy = false;
        if(in_array($wsip, $this->busyList)) {
            ArrayUtil::remove($wsip, $this->busyList);
            $removedFromBusy = true;
        }
        if(!$removedFromFree && !$removedFromBusy)
            BayLog::error("BUG:%s not in both free list and busy list", $wsip);

        parent::Return($wsip, $reuse);
        //BayLog::debug("Return: after freeList=%s busyList=%s", ArrayUtil::toString($this->keepList), ArrayUtil::toString($this->busyList));
    }

    public function count() : int
    {
        return count($this->keepList) + count($this->busyList);
    }

    public function busyCount() : int
    {
        return count($this->busyList);
    }

    /**
     * print memory usage
     */
    public function printUsage(int $indent) : void
    {
        BayLog::info("%sWarpShipStore Usage:", StringUtil::indent($indent));
        BayLog::info("%skeepList: %d", StringUtil::indent($indent+1), count($this->keepList));
        foreach($this->keepList as $obj) {
            BayLog::debug("%s%s", StringUtil::indent($indent+1), $obj);
        }
        BayLog::info("%sbusyList: %d", StringUtil::indent($indent+1), count($this->busyList));
        foreach($this->busyList as $obj) {
            BayLog::debug("%s%s", StringUtil::indent($indent+1), $obj);
        }
        parent::printUsage($indent);
    }

}