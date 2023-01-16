<?php

namespace baykit\bayserver\agent;

use baykit\bayserver\MemUsage;
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


class GrandAgent_CommandReceiver
{
    public $agent;
    public $readFd;
    public $writeFd;
    public $aborted = false;

    public function __construct($agent, $readFd, $writeFd)
    {
        $this->agent = $agent;
        $this->readFd = $readFd;
        $this->writeFd = $writeFd;
    }

    public function __toString()
    {
        return "ComReceiver#{$this->agent->agentId}";
    }

    public function onPipeReadable()
    {
        try {
            $cmd = IOUtil::recvInt32($this->readFd);
            if ($cmd == null) {
                BayLog::debug("%s pipe closed: %d", $this, $this->readFd);
                $this->agent->abort();
            }
            else {
                BayLog::debug("%s receive command %d pipe=%d", $this->agent, $cmd, $this->readFd);
                switch ($cmd) {
                    case GrandAgent::CMD_RELOAD_CERT:
                        $this->agent->reloadCert();
                        break;
                    case GrandAgent::CMD_MEM_USAGE:
                        $this->agent->printUsage();
                        break;
                    case GrandAgent::CMD_SHUTDOWN:
                        $this->agent->shutdown();
                        $this->aborted = true;
                        break;
                    case GrandAgent::CMD_ABORT:
                        IOUtil::sendInt32($this->writeFd, GrandAgent::CMD_OK);
                        $this->agent->abort();
                        return;
                    default:
                        BayLog::error("Unknown command: %d", $cmd);
                }

                IOUtil::sendInt32($this->writeFd, GrandAgent::CMD_OK);
            }
        }
        catch(\Exception $e) {
            BayLog::error_e($e, "%s Command thread aborted(end)", $this->agent);
        }
        finally {
            BayLog::debug("%s Command ended", $this);
        }
    }

    public function abort()
    {
        BayLog::debug("%s end", $this);
        IOUtil::sendInt32($this->writeFd, GrandAgent::CMD_CLOSE);
    }
}


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
    public static $agents = [];
    public static $agentPids = [];
    public static $listeners = [];
    public static $monitors = [];
    public static $agentCount = 0;
    public static $anchorablePortMap = [];
    public static $unanchorablePortMap = [];
    public static $maxShips = 0;
    public static $maxAgentId = 0;
    public static $multiCore = false;
    public static $finale = false;

    #
    # instance variables
    #
    public $agentId;
    public $nonBlockingHandler;
    public $spinHandler;
    public $acceptHandler;
    public $wakeupPipe;
    public $wakeupPipeNo;
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
        bool $anchorable,
        array $recvPipe,
        array $sendPipe)
    {
        $this->agentId = $agentId;
        $this->anchorable = $anchorable;

        if($anchorable)
            $this->acceptHandler = new AcceptHandler($this, GrandAgent::$anchorablePortMap);

        $this->nonBlockingHandler = new NonBlockingHandler($this);
        $this->spinHandler = new SpinHandler($this);

        $this->wakeupPipe = IOUtil::openLocalPipe();
        stream_set_blocking($this->wakeupPipe[0], false);
        stream_set_blocking($this->wakeupPipe[1], false);

        $this->selectTimeoutSec = GrandAgent::SELECT_TIMEOUT_SEC;
        $this->maxInboundShips = $maxShips;
        $this->selector = new Selector();
        $this->aborted = false;
        $this->commandReceiver = new GrandAgent_CommandReceiver($this, $recvPipe[0], $sendPipe[1]);
    }

    public function __toString() : string
    {
        return "Agt{$this->agentId}";
    }

    public function run()
    {
        BayLog::info(BayMessage::get(Symbol::MSG_RUNNING_GRAND_AGENT, $this));

        BayLog::debug("%s Register wakeup pipe read: %s (<-%s)", $this, $this->wakeupPipe[0], $this->wakeupPipe[1]);
        $this->selector->register($this->wakeupPipe[0], Selector::OP_READ);
        $this->selector->register($this->commandReceiver->readFd, Selector::OP_READ);


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
                try {
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

                    # BayLog.trace("%s Selected keys: %s", self, selkeys)
                    foreach ($selkeys as $key) {
                        if ($key->channel == $this->wakeupPipe[0]) {
                            # Waked up by ask_to_*
                            $this->onWakedUp($key->channel);
                        }
                        elseif ($key->channel == $this->commandReceiver->readFd) {
                            $this->commandReceiver->onPipeReadable();
                        }
                        elseif ($this->acceptHandler->isServerSocket($key->channel)) {
                            $this->acceptHandler->onAcceptable($key->channel);
                        }
                        else {
                            $this->nonBlockingHandler->handleChannel($key);
                        }
                        $processed = true;
                    }

                    if (!$processed) {
                        # timeout check if there is nothing to do
                        $this->nonBlockingHandler->closeTimeoutSockets();
                        $this->spinHandler->stopTimeoutSpins();
                    }

                } catch (\Throwable $e) {
                    BayLog::error("%s error: %s", $this, $e);
                    BayLog::error_e($e);
                    break;
                }
            }
        }
        finally {
            BayLog::debug("Agent end: %d", $this->agentId);
            $this->commandReceiver->abort();
            foreach (GrandAgent::$listeners as $lis) {
                $lis->remove($this->agentId);
            }
        }

    }

    public function shutdown() : void
    {
        BayLog::debug("%s shutdown", $this);
        if ($this->acceptHandler) {
            $this->acceptHandler->shutdown();
        }
        $this->aborted = true;
        $this->wakeup();

    }

    public function abort() : void
    {
        BayLog::debug("%s abort", $this);
        exit(1);
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
        IOUtil::sendInt32($this->wakeupPipe[1], 0);
    }

    ######################################################
    # class methods
    ######################################################
    public static function init(int $count, array $anchorablePortMap, array $unanchorablePortMap,int $maxShips, bool $multiCore)
    {
        self::$agentCount = $count;
        self::$anchorablePortMap = $anchorablePortMap;
        self::$unanchorablePortMap = $unanchorablePortMap;
        self::$maxShips = $maxShips;
        self::$multiCore = $multiCore;
        if(count(self::$unanchorablePortMap) > 0)
            self::add(false);
        for ($i = 0; $i < $count; $i++) {
            BayLog::debug("Add agent: %d", $i);
            self::add(true);
        }
    }

    public static function get(int $id) : GrandAgent
    {
        foreach (self::$agents as $agt) {
            if ($agt->agentId == $id) {
                return $agt;
            }
        }
        return false;
    }

    public static function add(bool $anchorable) : void
    {
        self::$maxAgentId += 1;
        $agentId = self::$maxAgentId;
        $sendPipe = IOUtil::openLocalPipe();
        if($sendPipe === false)
            throw new IOException("Cannot create local pipe");
        $recvPipe = IOUtil::openLocalPipe();

        if (self::$multiCore) {
            # Agents run on multi core (process mode)

            $pid = pcntl_fork();
            if ($pid == -1) {
                # Error
            } elseif ($pid == 0) {
                # Child process
                # train runners and tax runners run in the new process
                self::invokeRunners();

                $agt = new GrandAgent($agentId, BayServer::$harbor->maxShips, $anchorable, $sendPipe, $recvPipe);
                $agt->pid = $pid;
                self::$agents[] = $agt;
                foreach (GrandAgent::$listeners as $lis)
                    $lis->add($agt->agentId);

                if (SysUtil::runOnPhpStorm())
                    pcntl_signal(SIGINT, SIG_IGN);

                $agt->run();

                # Main thread sleeps until agent finished
                #$agt->join();
                exit(0);
            } else {
                self::$agentPids[] = $pid;

                $mon = new GrandAgentMonitor($agentId, $anchorable, $sendPipe, $recvPipe);
                self::$monitors[] = $mon;
            }
        } else {
            # Agents run on single core (thread mode)
            self::invokeRunners();

            $agt = new GrandAgent($agentId, self::$maxShips, $anchorable, $sendPipe, $recvPipe);
            self::$agents[] = $agt;
            foreach (self::$listeners as $lis) {
                $lis->add($agt->agentId);
            }
            $agt->run();

            $mon = new GrandAgentMonitor($agentId, $anchorable, $sendPipe, $recvPipe);
            self::$monitors[] = $mon;
        }
    }

    public static function reloadCertAll()
    {
        foreach(self::$monitors as $mon) {
            $mon->reloadCert();
        }
    }

    public static function restartAll()
    {
        $copied = self::$monitors;
        foreach($copied as $mon) {
            $mon->shutdown();
        }
    }

    public static function shutdownAll()
    {
        self::$finale = true;
        $copied = self::$monitors;
        foreach($copied as $mon) {
            $mon->shutdown();
        }
    }

    public static function abortAll()
    {
        BayLog::info("abortAll()");
        self::$finale = true;
        $copied = self::$monitors;
        foreach($copied as $mon) {
            $mon->abort();
        }
        exit(1);
    }

    public static function printUsageAll()
    {
        foreach(self::$monitors as $mon) {
            $mon->printUsage();
            sleep(1); // lazy implementation
        }
    }

    public static function addLifecycleListener(LifecycleListener $lis) : void
    {
        self::$listeners[] = $lis;
    }

    public static function agentAborted(int $agtId, $anchorable) : void
    {
        BayLog::info(BayMessage::get(Symbol::MSG_GRAND_AGENT_SHUTDOWN, $agtId));

        self::$agents = array_filter(
            self::$agents,
            function ($item) use ($agtId) { return $item->agentId == $agtId; });

        self::$monitors = array_filter(
            self::$monitors,
            function ($item) use ($agtId) {
                return $item->agentId != $agtId;
            });

        if (!self::$finale) {
            if (count(self::$agents) < self::$agentCount) {
                self::add($anchorable);
            }
        }
    }

    private static function invokeRunners()
    {
    }
}
