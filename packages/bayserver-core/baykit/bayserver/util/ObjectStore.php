<?php
namespace baykit\bayserver\util;

use baykit\bayserver\BayLog;
use baykit\bayserver\Sink;

class ObjectStore implements Reusable
{
    public $freeList = [];
    public $activeList = [];
    public $factory;


    public function __construct($factory=null)
    {
        $this->factory = $factory;
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        if (count($this->activeList) > 0) {
            BayLog::error("BUG?: There are %d active objects: %s", count($this->activeList), $this->activeList);
            // for security
            $this->freeList = [];
            $this->activeList = [];
        }
    }


    ////////////////////////////////////////////////////////////////////////////////
    // Other methods
    ////////////////////////////////////////////////////////////////////////////////

    public function rent() : ?object
    {
        if (count($this->freeList) == 0) {
            if ($this->factory instanceof ObjectFactory)
                $obj = $this->factory->createObject();
            else
                # lambda function
                $obj = ($this->factory)();
        }
        else
            $obj = array_pop($this->freeList);

        if ($obj === null)
            throw new Sink();

        $this->activeList[] = $obj;

        BayLog::trace("Rent object %s", $obj);

        return $obj;
    }

    public function Return(object $obj, bool $reuse=true) : void
    {
        BayLog::trace("Return object %s", $obj);

        if (in_array($obj, $this->freeList, true))
            throw new Sink("This object already returned: %s", $obj);

        if (!in_array($obj, $this->activeList, true))
            throw new Sink("This object is not active: %s", $obj);

        ArrayUtil::remove($obj, $this->activeList);

        if ($reuse) {
            $this->freeList[] = $obj;
            $obj->reset();
        }
    }

    public function printUsage(int $indent) : void
    {
        BayLog::info("%sfree list: %d", StringUtil::indent($indent), count($this->freeList));
        BayLog::info("%sactive list: %d", StringUtil::indent($indent), count($this->activeList));
        if (BayLog::isDebugMode()) {
            foreach ($this->activeList as $obj)
                BayLog::debug("%s%s", StringUtil::indent($indent + 1), $obj);
        }
    }
}

