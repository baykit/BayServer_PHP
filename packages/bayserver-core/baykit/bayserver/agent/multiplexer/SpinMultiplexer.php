<?php

namespace baykit\bayserver\agent\multiplexer;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\agent\PortMap;
use baykit\bayserver\agent\SpinHandler_ListenerInfo;
use baykit\bayserver\agent\SpinHandler_SpinListener;
use baykit\bayserver\agent\UpgradeException;
use baykit\bayserver\BayLog;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Sink;
use baykit\bayserver\BayServer;
use baykit\bayserver\agent\TimerHandler;
use \baykit\bayserver\common\RudderState;
use baykit\bayserver\common\Recipient;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\util\ArrayUtil;
use baykit\bayserver\util\BlockingIOException;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\Selector;
use baykit\bayserver\util\Selector_Key;
use baykit\bayserver\util\DataConsumeListener;
use baykit\bayserver\util\IOUtil;
use baykit\bayserver\util\SysUtil;

abstract class Lapper {
    public RudderState $state;
    public int $lastAccess;

    // Return if spun (method do nothing)
    abstract function lap(): bool;
    abstract function next(): void;

    function __construct(RudderState $state)
    {
        $this->state = $state;
        $this->access();
    }

    function access() : void
    {
        $this->lastAccess = time();
    }
}

class ReadLapper extends Lapper
{
    public RudderState $state;

    /**
     * @param RudderState $state
     */
    public function __construct(RudderState $state)
    {
        $this->state = $state;
        $this->state->rudder->setNonBlocking();
    }

    function lap(): bool
    {
        $spun = false;

        /*
        try {
            $eof = false;

            $n = $this->state->rudder->read($this->state->bufSize);
            if($n < 0) {
                $eof = true;
            }

            if($)
        }
        */
        return true;
    }

    function next(): void
    {
        // TODO: Implement next() method.
    }
}


class SpinMultiplexer extends MultiplexerBase implements TimerHandler
{

    private int $spinCount = 0;
    private array $runningList = [];

    public function __construct(GrandAgent $agt)
    {
        parent::__construct($agt);

        $this->agent->addTimerHandler($this);
    }

    public function __toString(): string {
        return "SpinMpx[" . $this->agent . "]";
    }

    ////////////////////////////////////////////
    // Implements Multiplexer
    ////////////////////////////////////////////
    public final function reqAccept(Rudder $rd): void
    {
        throw new Sink();
    }

    public final function reqConnect(Rudder $rd, string $addr): void
    {
        throw new Sink();
    }

    public final function reqRead(Rudder $rd): void
    {
        $st = $this->getRudderState($rd);

        $needRead = false;
        if(!$st->reading) {
            $st->reading = true;
            $needRead = true;
        }

        if($needRead) {
            $this->nextRead($st);
        }

        $st->access();
    }

    public function reqWrite(Rudder $rd, string $buf, ?string $adr, $tag, ?callable $callback): void
    {
        $st = $this->getRudderState($rd);

        $unt = new WriteUnit($buf, $adr, $tag, $callback);
        $st->writeQueue[] = $unt;

        $st->access();

        $needWrite = false;
        if(!$st->writing) {
            $needWrite = true;
            $st->writing = true;
        }

        if($needWrite) {
            $this->nextWrite($st);
        }
    }

    public function reqEnd(Rudder $rd): void {
        $st = $this->getRudderState($rd);
        $st->finale = true;
        $st->access();
    }

    public function reqClose(Rudder $rd): void {
        $st = $this->getRudderState($rd);
        BayLog::debug("%s reqClose chState=%s", $this->agent, $st);

        $st->closing = true;

        $this->closeRudder($st);
        $this->agent->sendClosedLetter($st, false);

        $st->access();
    }

    public final function cancelRead(RudderState $st) : void {
        BayLog::debug("%s Reading off %s", $this, $st->rudder);
        $st->reading = false;
    }

    public final function cancelWrite(RudderState $st) : void
    {
    }

    public final function nextRead(RudderState $st) : void
    {
        $lpr = new ReadLapper($st);

        $lpr->next();
        $this->runningList[] = $lpr;
    }

    public final function nextWrite(RudderState $st) : void
    {
        $lpr = new WriteLapper($st);

        $lpr->next();
        $this->runningList[] = $lpr;
    }

    public final function nextAccept(RudderState $st) : void
    {
    }

    public function shutdown(): void
    {
        $this->closeAll();
    }

    public final function isNonBlocking() : bool
    {
        return false;
    }

    public final function useAsyncAPI() : bool
    {
        return false;
    }

    public final function onBusy() : void
    {
        throw new Sink();
    }

    public final function onFree() : void
    {
        throw new Sink();
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
    public function isEmpty() : bool
    {
        return empty($this->runningList);
    }

    public function processData() : bool
    {
        if ($this->isEmpty())
            return false;

        $allSpun = true;
        $removeList = [];

        for ($i = count($this->runningList) - 1; $i >= 0; $i--) {
            $lpr = $this->runningList[$i];
            $st = $lpr->state;
            $spun = $lpr->lap();

            $st->access();
            $allSpun = $allSpun & $spun;
        }

        if ($allSpun) {
            $this->spinCount++;
            if ($this->spinCount > 10) {
                sleep(10);
            }
        } else {
            $this->spinCount = 0;
        }

        foreach ($removeList as $i) {
            ArrayUtil::removeByIndex($i, $this->runningList);
        }

        return true;
    }

    ////////////////////////////////////////////
    // Private methods
    ////////////////////////////////////////////

    private function stopTimeoutSpins() : void
    {
        if (empty($this->rudders))
            return;

        $removeList = [];
        $now = time();
        foreach ($this->rudders as $key => $st) {
            if ($st->transporter != null && $st->transporter->checkTimeout($st->rudder, $now - $st->lastAccessTime)) {
                $this->closeRudder($st->rudder);
                $removeList[] = $key;
            }
        }

        foreach ($removeList as $key) {
            ArrayUtil::remove($key, $this->rudders);
        }
    }
}









