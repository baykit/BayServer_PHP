<?php

namespace baykit\bayserver\agent\signal;

use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\bcf\BcfParser;
use baykit\bayserver\docker\built_in\BuiltInHarborDocker;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\SysUtil;

class SignalSender
{
    public $controlPort;
    public $pidFile;

    public function __construct()
    {
        $this->controlPort = BuiltInHarborDocker::DEFAULT_CONTROL_PORT;
        $this->pidFile = BuiltInHarborDocker::DEFAULT_PID_FILE;
    }

    /**
     * Send running BayServer a command
     */
    public function sendCommand($cmd)
    {
        $this->parseControlPort(BayServer::$bservPlan);

        if ($this->controlPort < 0) {
            $pid = $this->readPidFile();
            $sig = SignalAgent::getSignalFromCommand($cmd);
            if ($sig == null)
                throw new StandardError("Invalid command: " . $cmd);
            elseif ($pid <= 0)
                throw new StandardError("Invalid process ID: " . $pid);
            else {
                BayLog::info("Send signal pid=#{pid} sig={$sig}");
                $this->kill($pid, $sig);
            }
        }
        else {
            BayLog::info("Send command to running BayServer: cmd=%s port=%d", $cmd, $this->controlPort);
            $this->send("127.0.0.1", $this->controlPort, $cmd);
        }
    }


    /**
     * Parse plan file and get port number of SignalAgent
     */
    public function parseControlPort(string $plan)
    {
        $p = new BcfParser();
        $doc = $p->parse($plan);
        foreach($doc->contentList as $elm) {
            if ($elm instanceof BcfElement) {
                if (StringUtil::eqIgnorecase($elm->name, "harbor")) {
                    foreach ($elm->contentList as $kv) {
                        if (StringUtil::eqIgnorecase($kv->key, "controlPort")) {
                            $this->controlPort = intval($kv->value);
                        } elseif (StringUtil::eqIgnorecase($kv->key, "pidFile")) {
                            $this->pid_file = $kv->value;
                        }
                    }
                }
            }
        }
    }

    public function send(string $host, int $port, string $cmd)
    {
        try {
            if (($skt = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
                throw new IOException("Cannot create socket: " . socket_strerror(socket_last_error()));
            }

            if (socket_connect($skt, $host, $port) === false) {
                throw new IOException("Cannot connect to host: " . socket_strerror(socket_last_error()));
            }

            $cmd = $cmd . "\n";
            socket_send($skt, $cmd, strlen($cmd), 0);

            socket_recv($skt, $line, 128, MSG_WAITALL);
        }
        finally {
            if ($skt)
                fclose($skt);
        }
    }

    public function kill(int $pid, int $sig)
    {
        BayLog::info("Send signal pid=#{pid} sig={$sig}");
        if(SysUtil::runOnWindows()) {
            system("taskkill /PID {$pid} /F");
        }
        else {
            shell_exec("kill -${sig} ${pid}");
        }
    }

    public function readPidFile() : int
    {
        $f = fopen($this->pidFile, "r");
        if ($f === false)
            throw new IOException("Cannot open pid file: " . $this->pidFile);
        $line = stream_get_line($f, 256);
        fclose($f);
        return intval($line);
    }
}
