<?php
namespace baykit\bayserver\agent;

use baykit\bayserver\BayLog;
use baykit\bayserver\util\IOUtil;

class CommandReceiver
{
    public $agent;
    public $communicationChannel;
    public $aborted = false;

    public function __construct($agent, $comCh)
    {
        $this->agent = $agent;
        $this->communicationChannel = $comCh;
    }

    public function __toString()
    {
        return "ComReceiver#{$this->agent->agentId}";
    }

    public function onPipeReadable()
    {
        try {
            $cmd = IOUtil::recvInt32($this->communicationChannel);
            if ($cmd == null) {
                BayLog::debug("%s pipe closed: %d", $this, $this->communicationChannel);
                $this->agent->abort();
            }
            else {
                BayLog::debug("%s receive command %d pipe=%d", $this->agent, $cmd, $this->communicationChannel);
                switch ($cmd) {
                    case GrandAgent::CMD_RELOAD_CERT:
                        $this->agent->reloadCert();
                        break;
                    case GrandAgent::CMD_MEM_USAGE:
                        $this->agent->printUsage();
                        break;
                    case GrandAgent::CMD_SHUTDOWN:
                        $this->agent->reqShutdown();
                        $this->aborted = true;
                        break;
                    case GrandAgent::CMD_ABORT:
                        IOUtil::sendInt32($this->communicationChannel, GrandAgent::CMD_OK);
                        $this->agent->abort();
                        return;
                    default:
                        BayLog::error("Unknown command: %d", $cmd);
                }

                IOUtil::sendInt32($this->communicationChannel, GrandAgent::CMD_OK);
            }
        }
        catch(\Exception $e) {
            BayLog::error_e($e, "%s Command thread aborted(end)", $this);
            $this->abort();
        }
        finally {
            BayLog::debug("%s Command ended", $this);
        }
    }

    public function end()
    {
        BayLog::debug("%s end", $this);
        try {
            IOUtil::sendInt32($this->communicationChannel, GrandAgent::CMD_CLOSE);
        }
        catch(\Exception $e) {
            BayLog::error_e($e, "%s Write error", $this->agent);
        }
        $this->close();
    }
    public function close()
    {
        stream_socket_shutdown($this->communicationChannel, STREAM_SHUT_RDWR);
    }
}
