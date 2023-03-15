<?php

namespace baykit\bayserver\agent;

use baykit\bayserver\BayLog;
use baykit\bayserver\util\Selector;
use baykit\bayserver\util\SysUtil;


class AcceptHandler
{
    public $agent;
    public $portMap;
    public $chCount = 0;
    public $isShutdown = false;

    public function __construct($agent, $portMap)
    {
        $this->agent = $agent;
        $this->portMap = $portMap;
    }

    public function onAcceptable($ch) : void
    {
        BayLog::debug("%s on_acceptable", $this->agent);

        $portDkr = PortMap::findDocker($ch, $this->portMap);

        // Specifies timeout because in some cases accept() don't seem to work in no blocking mode.
        $timeoutSec = $portDkr->nonBlockingTimeoutMillis / 1000;

        //$level = error_reporting();
        //error_reporting(E_ERROR);

        if (($clientSkt = stream_socket_accept($ch, $timeoutSec)) === false) {
            //error_reporting($level);
            // Timeout or another agent get client socket
            if ($portDkr->secure()) {
                while ($msg = openssl_error_string())
                    BayLog::error("%s SSL Error: %s", $this->agent, $msg);
                #BayLog::debug("%s Cert error or plain text", $this->agent);
            }
            BayLog::debug("%s [port=%d] Error: %s", $this->agent, $portDkr->port(), SysUtil::lastErrorMessage());
            return;
        }
        //error_reporting($level);

        BayLog::debug("%s Accepted: skt=%s", $this->agent, $clientSkt);
        $params = stream_context_get_params($clientSkt);
        $opts = stream_context_get_options($clientSkt);

        try {
            $portDkr->checkAdmitted($clientSkt);
        }
        catch(\Exception $e) {
            BayLog::error_e($e);
            socket_close($clientSkt);
            return;
        }

        stream_set_blocking($clientSkt, false);
        if ($portDkr->secure()) {
            // SSL stream socket does not work as nonblocking.
            stream_set_blocking($clientSkt, true);
            stream_set_timeout($clientSkt, 0, $portDkr->nonBlockingTimeoutMillis * 1000);
        }

        $tp = $portDkr->newTransporter($this->agent, $clientSkt);

        # In SSL mode, since Socket object is replaced to SSLSocket, we must update "ch" variable
        $clientSkt = $tp->ch;
        $this->agent->nonBlockingHandler->askToStart($clientSkt);
        $this->agent->nonBlockingHandler->askToRead($clientSkt);
        $this->chCount += 1;

    }

    public function onClosed()
    {
        $this->chCount -= 1;
    }

    public function onBusy()
    {
        BayLog::debug("%s AcceptHandler:onBusy", $this->agent);
        foreach ($this->portMap as $map) {
            $this->agent->selector->unregister($map->ch);
        }
    }

    public function onFree()
    {
        BayLog::debug("%s AcceptHandler:onFree isShutdown=%b", $this->agent, $this->isShutdown);
        if ($this->isShutdown)
            return;

        foreach ($this->portMap as $map) {
            try {
                BayLog::debug("%s Register server socket: %d", $this->agent, $map->ch);
                $this->agent->selector->register($map->ch, Selector::OP_READ);
            } catch (\Exception $e) {
                BayLog::error_e($e);
            }
        }
    }

    public function isServerSocket($ch)
    {
        foreach($this->portMap as $map) {
            if ($map->ch == $ch)
                return true;
        }
        return false;
    }

    public function shutdown() {
        $this->isShutdown = true;
    }
}