<?php

namespace baykit\bayserver\agent;

use baykit\bayserver\BayLog;
use baykit\bayserver\Sink;
use baykit\bayserver\util\ArrayUtil;

interface SpinHandler_SpinListener
{
    public function lap() : array;
    public function checkTimeout(int $durationSec) : bool;
    public function close() : void;
}

class SpinHandler_ListenerInfo {
    public $listener;
    public $lastAccess;

    public function __construct($listener, $lastAccess) {
        $this->listener = $listener;
        $this->lastAccess = $lastAccess;
    }
}

class SpinHandler implements TimerHandler
{
    public $listeners = [];
    public $agent;
    public $spinCount;

    public function __construct($agt)
    {
        $this->agent = $agt;
        $this->spinCount = 0;
        $this->agent->addTimerHandler($this);
    }

    public function __toString() : string
    {
        return (string)$this->agent;
    }

    //////////////////////////////////////////////////////
    // Implements TimerHandler
    //////////////////////////////////////////////////////
    public function onTimer(): void
    {
        $this->stopTimeoutSpins();
    }

    //////////////////////////////////////////////////////
    // Custom methods
    //////////////////////////////////////////////////////
    public function processData() : bool
    {
        if (count($this->listeners) == 0)
            return false;

        $allSpun = true;
        $removeList = [];
        for ($i  = count($this->listeners) - 1; $i >= 0; $i--) {
            $lis = $this->listeners[$i]->listener;
            list($act, $spun) = $lis->lap();

            switch ($act) {
                case NextSocketAction::SUSPEND:
                case NextSocketAction::CLOSE:
                    $removeList[] = $i;
                    break;
                case NextSocketAction::CONTINUE:
                    break;
                default:
                    throw new Sink("Invalid next state");
            }

            $this->listeners[$i]->lastAccess = time();
            $allSpun = $allSpun & $spun;
        }

        if($allSpun) {
            $this->spinCount++;
            if($this->spinCount > 10) {
                sleep(0.01);
            }
        }
        else {
            $this->spinCount = 0;
        }

        foreach ($removeList as $i)
            ArrayUtil::removeByIndex($i, $this->listeners);

        return true;
    }

    public function askToCallBack(SpinHandler_SpinListener $listener) : void
    {
        BayLog::debug("%s Ask to callback: %s", $this, $listener);

        $found = false;
        foreach($this->listeners as $ifo) {
            if ($ifo->listener == $listener) {
                $found = true;
                break;
            }
        }

        if($found) {
            BayLog::error("Already registered");
        }
        else {
            $this->listeners[] = new SpinHandler_ListenerInfo($listener, time());
        }
    }

    public function isEmpty() : bool
    {
        return count($this->listeners) == 0;
    }


    public function stopTimeoutSpins() : void
    {
        if (count($this->listeners) == 0)
            return;

        $removeList = [];
        $now = time();
        for ($i = count($this->listeners) - 1; $i >= 0; $i--) {
            $ifo = $this->listeners[$i];
            if ($ifo->listener->checkTimeout($now - $ifo->lastAccess)) {
                $ifo->listener->close();
                $removeList[] = $i;
            }
        }

        foreach ($removeList as $i) {
            ArrayUtil::removeByIndex($i, $this->listeners);
        }
    }
}