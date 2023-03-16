<?php

namespace baykit\bayserver\agent;


use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\Symbol;
use baykit\bayserver\util\ArrayUtil;
use baykit\bayserver\util\BlockingIOException;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\IOUtil;
use baykit\bayserver\util\SysUtil;
use Cassandra\BatchStatement;

class GrandAgentMonitor
{
    public $agentId;
    public $anchorable;
    public $communicationChannel;

    static $numAgents = 0;
    static $curId = 0;
    static $monitors = [];
    static $anchoredPortMap = [];
    static $unanchoredPortMap = [];
    static $finale = false;

    public function __construct(int $agtId, bool $anchorable, $comChannel)
    {
        $this->agentId = $agtId;
        $this->anchorable = $anchorable;
        $this->communicationChannel = $comChannel;
        if (stream_set_blocking($comChannel, false) === false) {
            throw new IOException("Cannot set nonblock: " . SysUtil::lastErrorMessage());
        }
    }

    public function __toString()
    {
        return "Monitor#{$this->agentId}";
    }

    public function onReadable()
    {
         try {
            $res = IOUtil::recvInt32($this->communicationChannel);
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
            GrandAgentMonitor::agentAborted($this->agentId, $this->anchorable);
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
        BayLog::debug("%s send command %s pipe=%s", $this, $cmd, $this->communicationChannel);
        IOUtil::sendInt32($this->communicationChannel, $cmd);
    }

    public function close() : void
    {
        stream_socket_shutdown($this->communicationChannel, STREAM_SHUT_RDWR);
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

        $comCh = stream_socket_pair(AF_UNIX, SOCK_STREAM, 0);
        if (BayServer::$harbor->multiCore) {
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
            $newArgv[] = "-sockets=" . join(",", $portNos);
            #$newArgv[] = "-monitorSockets=" . $comCh[1].fileno;

            $descriptorSpec = [];
            $pipes = [];
            #$proc = $process = proc_open($newArgv, $descriptorSpec, $pipes);

            $pid = pcntl_fork();
            if ($pid == -1) {
                # Error
            } elseif ($pid == 0) {
                # Child process
                BayServer::initChild($comCh[1]);
                BayServer::main($newArgv);

                if (SysUtil::runOnPhpStorm())
                    pcntl_signal(SIGINT, SIG_IGN);

                exit(0);
            }
        }
        self::$monitors[$agtId] = new GrandAgentMonitor($agtId, $anchorable, $comCh[0]);

        if(!BayServer::$harbor->multiCore) {
            GrandAgent::add($agtId, $anchorable);
            $agt = GrandAgent::get($agtId);
            $agt->runCommandReceiver($comCh[1]);
            $agt->run();
        }
    }

    public static function agentAborted(int $agtId, $anchorable) : void
    {
        BayLog::info(BayMessage::get(Symbol::MSG_GRAND_AGENT_SHUTDOWN, $agtId));

        # remove from array
        self::$monitors = array_filter(
            self::$monitors,
            function ($item) use ($agtId) {
                return $item->agentId != $agtId;
            });

        if (!self::$finale) {
            if (count(self::$monitors) < self::$numAgents) {
                try {
                    if (!BayServer::$harbor->multiCore) {
                        GrandAgent::add(-1, $anchorable);
                    }
                    self::add($anchorable);
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