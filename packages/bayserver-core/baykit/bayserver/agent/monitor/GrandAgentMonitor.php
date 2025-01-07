<?php

namespace baykit\bayserver\agent\monitor;


use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\signal\SignalAgent;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\rudder\SocketRudder;
use baykit\bayserver\rudder\StreamRudder;
use baykit\bayserver\Symbol;
use baykit\bayserver\util\BlockingIOException;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\IOUtil;
use baykit\bayserver\util\Selector;
use baykit\bayserver\util\SysUtil;

class GrandAgentMonitor
{
    public int $agentId;
    public bool $anchorable;
    public $comChannel;
    public $agentProcess;

    static int $numAgents = 0;
    static int $curId = 0;
    static array $monitors = [];
    static $anchoredPortMap = [];
    static $unanchoredPortMap = [];
    static bool $finale = false;

    public function __construct(int $agtId, bool $anchorable, $comChannel, $agentProcess)
    {
        $this->agentId = $agtId;
        $this->anchorable = $anchorable;
        $this->comChannel = $comChannel;
        stream_set_blocking($this->comChannel, false);
        $this->agentProcess = $agentProcess;
    }

    public function __toString()
    {
        return "Monitor#{$this->agentId}";
    }

    public function onReadable()
    {
        try {
            $res = IOUtil::recvInt32($this->comChannel);
        }
        catch(BlockingIOException $e) {
            BayLog::debug("%s No data", $this);
            return;
        }
        catch(IOException $e) {
            BayLog::error_e($e);
            $res = GrandAgent::CMD_CLOSE;
        }

        if ($res == GrandAgent::CMD_CLOSE) {
            BayLog::debug("%s read Close", $this);
            $this->close();
            $this->agentAborted();
        }
        else {
            BayLog::debug("%s read OK: %d", $this, $res);
        }
    }

    public function shutdown() : void
    {
        BayLog::debug("%s send shutdown command", $this);
        $this->send(GrandAgent::CMD_SHUTDOWN);
    }

    public function abort() : void
    {
        BayLog::debug("%s send abort command", $this);
        $this->send(GrandAgent::CMD_ABORT);
    }

    public function reloadCert() : void
    {
        BayLog::debug("%s send reload command", $this);
        $this->send(GrandAgent::CMD_RELOAD_CERT);
    }

    public function printUsage() : void
    {
        BayLog::debug("%s send mem_usage command", $this);
        $this->send(GrandAgent::CMD_MEM_USAGE);
    }

    public function send(int $cmd) : void
    {
        BayLog::debug("%s send command %s pipe=%s", $this, $cmd, $this->comChannel);
        IOUtil::writeInt32($this->comChannel, $cmd);
    }

    public function close() : void
    {
        stream_socket_shutdown($this->comChannel, STREAM_SHUT_RDWR);
    }

    ######################################################
    # class methods
    ######################################################

    public static function init(int $numAgents, array &$anchoredPortMap, array &$unanchoredPortMap)
    {
        self::$numAgents = $numAgents;
        self::$anchoredPortMap = $anchoredPortMap;
        self::$unanchoredPortMap = $unanchoredPortMap;

        if($unanchoredPortMap != null && count($unanchoredPortMap) > 0) {
            self::add(false);
            self::$numAgents += 1;
        }

        for($i = 0; $i < $numAgents; $i++) {
            self::add(true);
        }
    }

    public static function add(bool $anchorable)
    {
        self::$curId++;
        $agtId = self::$curId;
        if($agtId > 100) {
            BayLog::error("Too many agents started");
            exit(1);
        }

        if (BayServer::$harbor->multiCore()) {
            $args = BayServer::$commandlineArgs;
            $newArgv = $args;
            //ArrayUtil::insert("php", $newArgv, 0);
            $newArgv[] = "-agentid=" . $agtId;


            $portNos = [];
            if($anchorable) {
                foreach(array_keys(self::$anchoredPortMap) as $ch) {
                    $portNos[] = $ch;
                }
            }
            else {
                foreach(array_keys(self::$unanchoredPortMap) as $ch) {
                    $portNos[] = $ch;
                }
            }

            if(SysUtil::runOnWindows()) {
                $server = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
                $address = stream_socket_get_name($server, false);
                $port = intval(explode(":", $address)[1]);

                $descriptorSpec = [];
                $pipes = [];
                $newArgv[] = "-monitorPort=" . $port;
                array_unshift($newArgv, "php");
                $proc = proc_open($newArgv, $descriptorSpec, $pipes);

                $client = stream_socket_accept($server);
                stream_socket_shutdown($server,  STREAM_SHUT_RDWR );

                $agentProcess = $proc;
            }
            else {
                $comCh = IOUtil::openLocalPipe();
                $pid = pcntl_fork();
                if ($pid == -1) {
                    # Error
                }
                elseif ($pid == 0) {
                    # Child process
                    $newArgv[] = "-sockets=" . join(",", $portNos);
                    BayServer::initChild($comCh[1]);
                    BayServer::main($newArgv);

                    if (SysUtil::runOnPhpStorm())
                        pcntl_signal(SIGINT, SIG_IGN);

                    exit(0);
                }

                $client = $comCh[0];
                $agentProcess = $pid;
            }
        }
        else {
            $client = null;
            $agentProcess = null;
        }
        self::$monitors[$agtId] = new GrandAgentMonitor($agtId, $anchorable, $client, $agentProcess);

        if(!BayServer::$harbor->multiCore()) {
            GrandAgent::add($agtId, $anchorable);
            $agt = GrandAgent::get($agtId);
            $agt->runCommandReceiver($comCh[1]);
            $agt->run();
        }
    }

    public static function start() : void {
        while (count(GrandAgentMonitor::$monitors) > 0) {
            $sel = new Selector();
            $monitors = [];
            foreach (GrandAgentMonitor::$monitors as $mon) {
                BayLog::debug("Monitoring pipe of %s", $mon);
                $sel->register($mon->comChannel, Selector::OP_READ);
                $monitors[] = $mon;
            }

            $serverSkt = null;
            if (SignalAgent::$signalAgent) {
                $serverSkt = SignalAgent::$signalAgent->serverSkt;
                $sel->register($serverSkt, Selector::OP_READ);
            }

            try {
                $selkeys = $sel->select();
            }
            catch (IOException $e) {
                BayLog::warn_e($e);
                pcntl_signal_dispatch();
                continue;
            }

            BayLog::debug("select %d keys", count($selkeys));
            foreach($selkeys as $selkey) {
                if ($selkey->channel == $serverSkt) {
                    SignalAgent::$signalAgent->onSocketReadable();
                }
                else {
                    foreach($monitors as $mon) {
                        if ($mon->comChannel === $selkey->channel)
                            $mon->onReadable();
                    }
                }
            }
        }

    }

    private function agentAborted() : void
    {
        BayLog::info(BayMessage::get(Symbol::MSG_GRAND_AGENT_SHUTDOWN, $this->agentId));

        if(SysUtil::runOnWindows()) {
            proc_terminate($this->agentProcess);
            proc_close($this->agentProcess);
        }
        else {
            // pcntl_kill is unavailable on some systems
            // pcntl_kill($this->agentProc, SIGTERM);
            exec("kill -TERM $this->agentProcess");
            pcntl_waitpid($this->agentProcess, $status);
        }
        # remove from array
        self::$monitors = array_filter(
            self::$monitors,
            function ($item) {
                return $item->agentId != $this->agentId;
            });

        if (!self::$finale) {
            if (count(self::$monitors) < self::$numAgents) {
                try {
                    if (!BayServer::$harbor->multiCore()) {
                        GrandAgent::add(-1, $this->anchorable);
                    }
                    self::add($this->anchorable);
                }
                catch(\Exception $e) {
                    BayLog::error_e($e);
                }
            }
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
}