<?php

namespace baykit\bayserver\agent;

use baykit\bayserver\MemUsage;
use baykit\bayserver\util\ArrayUtil;
use baykit\bayserver\util\BlockingIOException;
use baykit\bayserver\util\IOException;
use parallel\Runtime;
use baykit\bayserver\BayServer;
use baykit\bayserver\BayLog;
use baykit\bayserver\Sink;
use baykit\bayserver\BayMessage;
use baykit\bayserver\Symbol;
use baykit\bayserver\util\SysUtil;
use baykit\bayserver\util\IOUtil;
use baykit\bayserver\util\Selector;




class GrandAgent
{
    const SELECT_TIMEOUT_SEC = 10;

    const CMD_OK = 0;
    const CMD_CLOSE = 1;
    const CMD_RELOAD_CERT = 2;
    const CMD_MEM_USAGE = 3;
    const CMD_SHUTDOWN = 4;
    const CMD_ABORT = 5;

    #
    # class variables
    #
    public static $agentCount = 0;
    public static $maxShips = 0;
    public static $maxAgentId = 0;
    public static $multiCore = false;

    public static $agents = [];
    public static $listeners = [];

    public static $anchorablePortMap = [];
    public static $unanchorablePortMap = [];
    public static $finale = false;

    #
    # instance variables
    #
    public $agentId;
    public $nonBlockingHandler;
    public $spinHandler;
    public $acceptHandler;
    public $selectWakeupPipe = [];
    public $selectTimeoutSec;
    public $maxInboundShips;
    public $selector;
    public $aborted;
    public $commandReceiver;
    public $pid;
    public $anchorable;
    public $unanchorableTransporters = [];


    public function __construct(
        int $agentId,
        int $maxShips,
        bool $anchorable)
    {
        $this->agentId = $agentId;
        $this->anchorable = $anchorable;

        if($anchorable)
            $this->acceptHandler = new AcceptHandler($this, GrandAgent::$anchorablePortMap);

        $this->nonBlockingHandler = new NonBlockingHandler($this);
        $this->spinHandler = new SpinHandler($this);

        $this->selectWakeupPipe = IOUtil::openLocalPipe();
        stream_set_blocking($this->selectWakeupPipe[0], false);
        stream_set_blocking($this->selectWakeupPipe[1], false);

        $this->selectTimeoutSec = GrandAgent::SELECT_TIMEOUT_SEC;
        $this->maxInboundShips = $maxShips;
        $this->selector = new Selector();
        $this->aborted = false;
        $this->commandReceiver = null;
    }

    public function __toString() : string
    {
        return "Agt#{$this->agentId}";
    }

    public function run()
    {
        BayLog::info(BayMessage::get(Symbol::MSG_RUNNING_GRAND_AGENT, $this));

        BayLog::debug("%s Register wakeup pipe read: %s (<-%s)", $this, $this->selectWakeupPipe[0], $this->selectWakeupPipe[1]);
        $this->selector->register($this->selectWakeupPipe[0], Selector::OP_READ);
        $this->selector->register($this->commandReceiver->communicationChannel, Selector::OP_READ);


        // Set up unanchorable channel
        foreach (GrandAgent::$unanchorablePortMap as $ch => $port) {
            $tp = $port->newTransporter($this, $ch);
            GrandAgent::$unanchorablePortMap[$ch] = $tp;
            $this->nonBlockingHandler->addChannelListener($ch, $tp);
            $this->nonBlockingHandler->askToStart($ch);
            if(!$this->anchorable) {
                $this->nonBlockingHandler->askToRead($ch);
            }
        }


        $busy = true;

        try {
            while (true) {
                if ($this->acceptHandler != null) {
                    $test_busy = $this->acceptHandler->chCount >= $this->maxInboundShips;
                    if ($test_busy != $busy) {
                        $busy = $test_busy;
                        if ($busy)
                            $this->acceptHandler->onBusy();
                        else
                            $this->acceptHandler->onFree();

                        if (!$busy and count($this->selector->keys) <= 1) {
                            # agent finished
                            BayLog::debug("%s Selector has no key", $this);
                            break;
                        }
                    }
                }

                if ($this->aborted) {
                    // agent finished
                    BayLog::debug("%s End loop", $this);
                    break;
                }

                if (!$this->spinHandler->isEmpty())
                    $selkeys = $this->selector->select(0);
                else
                    $selkeys = $this->selector->select($this->selectTimeoutSec);

                $processed = $this->nonBlockingHandler->registerChannelOps() > 0;

                if (count($selkeys) == 0)
                    $processed |= $this->spinHandler->processData();


                foreach ($selkeys as $key) {
                    if ($key->channel == $this->selectWakeupPipe[0]) {
                        # Waked up by ask_to_*
                        $this->onWakedUp($key->channel);
                    } elseif ($key->channel == $this->commandReceiver->communicationChannel) {
                        $this->commandReceiver->onPipeReadable();
                    } elseif ($this->acceptHandler->isServerSocket($key->channel)) {
                        $this->acceptHandler->onAcceptable($key->channel);
                    } else {
                        $this->nonBlockingHandler->handleChannel($key);
                    }
                    $processed = true;
                }

                if (!$processed) {
                    # timeout check if there is nothing to do
                    $this->nonBlockingHandler->closeTimeoutSockets();
                    $this->spinHandler->stopTimeoutSpins();
                }
            }
        }
        catch (\Throwable $e) {
            BayLog::fatal_e($e, "%s fatal error", $this);
        }
        finally {
            BayLog::debug("Agent end: %d", $this->agentId);
            $this->abort(null, 0);
        }

    }

    public function shutdown() : void
    {
        BayLog::debug("%s shutdown", $this);
        if ($this->acceptHandler) {
            $this->acceptHandler->shutdown();
        }
        $this->abort(null, 0);

    }

    public function abort(\Exception $err=null, int $status=1) : void
    {
        if($err)
            BayLog::fatal_e($err, "%s abort", $this);

        $this->commandReceiver->end();
        foreach (GrandAgent::$listeners as $lis) {
            $lis->remove($this->agentId);
        }

        # remove from array
        self::$agents = array_filter(
            self::$agents,
            function ($item)  {
                return $item->agentId != $this->agentId;
            });

        if(BayServer::$harbor->multiCore) {
            exit(1);
        }
        else {
            $this->clean();
        }
    }

    public function reloadCert() : void
    {
        foreach(GrandAgent::$anchorablePortMap as $map) {
            if ($map->docker->secure()) {
                try {
                    $map->docker->secureDocker->reloadCert();
                }
                catch(\Exception $e) {
                    BayLog::error_e(e);
                }
            }
        }
    }

    public function printUsage() : void
    {
        # print memory usage
        BayLog::info("Agent#%d MemUsage", $this->agentId);
        MemUsage::get($this->agentId)->printUsage(1);
    }

    public function onWakedUp($ch) : void
    {
        BayLog::trace("%s On Waked Up", $this);
        try {
            while (true) {
                IOUtil::recvInt32($ch);
            }
        }
        catch(BlockingIOException $e) {
            /* Data not received */
        }
    }

    public function wakeup() : void
    {
        IOUtil::sendInt32($this->selectWakeupPipe[1], 0);
    }

    public function runCommandReceiver($comChannel)
    {
        $this->commandReceiver = new CommandReceiver($this, $comChannel);
    }

    public function clean() {
        $this->nonBlockingHandler->closeAll();
    }

    ######################################################
    # class methods
    ######################################################
    public static function init(array $agtIds, array $anchorablePortMap, array $unanchorablePortMap,int $maxShips, bool $multiCore)
    {
        self::$agentCount = count($agtIds);
        self::$anchorablePortMap = $anchorablePortMap;
        self::$unanchorablePortMap = $unanchorablePortMap;
        self::$maxShips = $maxShips;
        self::$multiCore = $multiCore;

        if (BayServer::$harbor->multiCore) {
            if(count(self::$unanchorablePortMap) > 0) {
                self::add($agtIds[0], false);
                ArrayUtil::removeByIndex(0, $agtIds);
            }

            foreach ($agtIds as $id) {
                #BayLog::debug("Add agent: %d", $id);
                self::add($id, true);
            }
        }
    }

    public static function get(int $id) : GrandAgent
    {
        return self::$agents[$id];
    }

    public static function add(int $agtId, bool $anchorable) : void
    {
        if ($agtId == -1)
            $agtId = self::$maxAgentId + 1;

        BayLog::debug("Add agent: id=%d", $agtId);

        if ($agtId > self::$maxAgentId)
            self::$maxAgentId = $agtId;

        $agt = new GrandAgent($agtId, BayServer::$harbor->maxShips, $anchorable);
        self::$agents[$agtId] = $agt;

        foreach (self::$listeners as $lis) {
            $lis->add($agt->agentId);
        }
    }


    public static function addLifecycleListener(LifecycleListener $lis) : void
    {
        self::$listeners[] = $lis;
    }

    private static function invokeRunners()
    {
    }
}
