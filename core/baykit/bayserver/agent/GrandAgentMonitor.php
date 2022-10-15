<?php

namespace baykit\bayserver\agent;


use baykit\bayserver\BayLog;
use baykit\bayserver\util\BlockingIOException;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\IOUtil;
use baykit\bayserver\util\SysUtil;

class GrandAgentMonitor
{
    public $agentId;
    public $anchorable;
    public $sendPipe;
    public $recvPipe;

    public function __construct(int $agtId, bool $anchorable, array $sendPipe, array $recvPipe)
    {
        $this->agentId = $agtId;
        $this->anchorable = $anchorable;
        $this->sendPipe = $sendPipe;
        $this->recvPipe = $recvPipe;
        if (stream_set_blocking($recvPipe[0], false) === false) {
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
            while(true) {
                $res = IOUtil::recvInt32($this->recvPipe[0]);
                if ($res == GrandAgent::CMD_CLOSE) {
                    BayLog::debug("%s read Close", $this);
                    GrandAgent::agentAborted($this->agentId, $this->anchorable);
                }
                else {
                    BayLog::debug("%s read OK: %d", $this, $res);
                }
            }
        }
        catch(BlockingIOException $e) {
            BayLog::debug("%s No data", $this);
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
        BayLog::debug("%s send command %s pipe=%s", $this, $cmd, $this->sendPipe[1]);
        IOUtil::sendInt32($this->sendPipe[1], $cmd);
    }

    public function close() : void
    {
        $this->sendPipe[0].close();
        $this->sendPipe[1].close();
        $this->recvPipe[0].close();
        $this->recvPipe[1].close();
    }
}