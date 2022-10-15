<?php

namespace baykit\bayserver\agent\signal;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\Symbol;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\SysUtil;


class SignalAgent
{
    const COMMAND_RELOAD_CERT = "reloadcert";
    const COMMAND_MEM_USAGE = "memusage";
    const COMMAND_RESTART_AGENTS = "restartagents";
    const COMMAND_SHUTDOWN = "shutdown";
    const COMMAND_ABORT = "abort";

    public static $signalAgent = null;
    public static $commands = [];
    public static $signalMap = [];

    public $port;
    public $serverSkt;
    public $closed;

    public function __construct(int $port)
    {
        $this->closed = false;
        $this->port = $port;
        $this->serverSkt = stream_socket_server(
            "tcp://127.0.0.1:" . $this->port,
            $errno,
            $errstr);

        if ($this->serverSkt == false)
            throw new \Exception("Cannot open port: $errstr ($errno)");

        if (stream_set_blocking($this->serverSkt, true) == false)
            throw new \Exception("Cannot set non blocking: " . socket_strerror(socket_last_error()));

        BayLog::info(BayMessage::get(Symbol::MSG_OPEN_CTL_PORT, $this->port));
    }

    public function onSocketReadable()
    {
        try {
            if (($clientSkt = stream_socket_accept($this->serverSkt)) === false) {
                BayLog::debug("Accept error: %d (%s))", socket_last_error(), socket_strerror(socket_last_error()));
                return;
            }
            stream_set_timeout($clientSkt, 5);

            $line = "";
            while(true) {
                $c = fread($clientSkt, 1);
                if ($c == "\n" || $c == "")
                    break;
                $line .= $c;
            }
            BayLog::info(BayMessage::get(Symbol::MSG_COMMAND_RECEIVED, $line));
            SignalAgent::handleCommand($line);
            fwrite($clientSkt, "OK\n");
            fflush($clientSkt);
        }
        catch(\Exception $e) {
            BayLog::error_e($e);
        }
    }

    public function close()
    {
        $this->closed = true;
        fclose($this->serverSkt);
    }

    /////////////////////////////////////////////////////////////////////////////////
    // class functions
    /////////////////////////////////////////////////////////////////////////////////

    public static function init(int $port)
    {
        if ($port > 0) {
            self::$signalAgent = new SignalAgent($port);
        }
        else {
            self::$commands = [
                self::COMMAND_RELOAD_CERT,
                self::COMMAND_MEM_USAGE,
                self::COMMAND_RESTART_AGENTS,
                self::COMMAND_SHUTDOWN,
                self::COMMAND_ABORT
            ];

            foreach (self::$commands as $cmd) {
                $sig = self::getSignalFromCommand($cmd);

                SignalProxy::register($sig, function (int $signo, $info) use ($cmd) {
                    BayLog::info("signal: %d %s", $signo, $cmd);
                    self::handleCommand($cmd);
                });
            }

            if ($port > 0) {
                self::$signalAgent = new self($port);
            }
        }
    }

    public static function handleCommand(string $cmd)
    {
        BayLog::debug("handle command: %s", $cmd);
        switch (strtolower($cmd)) {
            case self::COMMAND_RELOAD_CERT:
                GrandAgent::reloadCertAll();
                break;

            case self::COMMAND_MEM_USAGE:
                GrandAgent::printUsageAll();
                break;

            case self::COMMAND_RESTART_AGENTS:
                GrandAgent::restartAll();
                break;

            case self::COMMAND_SHUTDOWN:
                GrandAgent::shutdownAll();
                break;

            case self::COMMAND_ABORT:
                GrandAgent::abortAll();
                break;

            default:
                BayLog::error("Unknown command: %s", $cmd);

        }
    }

    public static function getSignalFromCommand($command) : int
    {
        self::initSignalMap();
        foreach (self::$signalMap as $sig => $cmd) {

            if (StringUtil::eqIgnorecase($cmd, $command)) {
                return $sig;
            }
        }
        return -1;
    }

    public static function initSignalMap()
    {
        if (count(self::$signalMap) > 0)
            return;

        if (SysUtil::runOnWindows()) {
            # Available signals on Windows
            #    SIGABRT
            #    SIGFPE
            #    SIGILL
            #    SIGINT
            #    SIGSEGV
            #    SIGTERM
            #self::$signalMap[SIGSEGV] = self::COMMAND_RELOAD_CERT;
            #self::$signalMap[SIGILL] = self::COMMAND_MEM_USAGE;
            #self::$signalMap[SIGINT] = self::COMMAND_SHUTDOWN;
            #self::$signalMap[SIGTERM] = self::COMMAND_RESTART_AGENTS;
            #self::$signalMap[SIGALRM] = self::COMMAND_ABORT;
        }
        else {
            self::$signalMap[SIGALRM] = self::COMMAND_RELOAD_CERT;
            self::$signalMap[SIGTRAP] = self::COMMAND_MEM_USAGE;
            self::$signalMap[SIGHUP] = self::COMMAND_RESTART_AGENTS;
            self::$signalMap[SIGTERM] = self::COMMAND_SHUTDOWN;
            self::$signalMap[SIGABRT] = self::COMMAND_ABORT;
        }
    }

    public static function term()
    {
        if (self::$signalAgent) {
            self::$signalAgent->close();
        }
    }
}