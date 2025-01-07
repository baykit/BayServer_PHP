<?php
namespace baykit\bayserver\agent;

use baykit\bayserver\BayLog;
use baykit\bayserver\common\Transporter;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\ship\Ship;
use baykit\bayserver\Sink;
use baykit\bayserver\util\IOUtil;

class CommandReceiver extends Ship
{
    private bool $closed = false;

    public function __toString()
    {
        return "ComReceiver#{$this->agentId}";
    }

    public function init(int $agtId, Rudder $rd, ?Transporter $tp): void
    {
        parent::init($agtId, $rd, $tp);
    }

    ////////////////////////////////////////////
    // Implements Ship
    ////////////////////////////////////////////
    public function notifyHandshakeDone(string $pcl): int
    {
        throw new Sink();
    }

    public function notifyConnect(): int
    {
        throw new Sink();
    }

    public function notifyRead(string $buf): int
    {
        $cmd =  unpack('N', $buf)[1];
        $this->onReadCommand($cmd);
        return NextSocketAction::CONTINUE;
    }

    public function notifyEof(): int
    {
        BayLog::debug("%s notify_eof", $this);
        return NextSocketAction::CLOSE;
    }

    public function notifyError(\Exception $e): void
    {
        BayLog::error_e($e);
    }

    public function notifyProtocolError(ProtocolException $e): bool
    {
        throw new Sink();
    }

    public function notifyClose(): void
    {
        BayLog::debug("%s notify close", $this);
    }

    public function checkTimeout(int $durationSec): bool
    {
        BayLog::debug("%s check timeout", $this);
        return false;
    }

    ////////////////////////////////////////////
    // Custom methods
    ////////////////////////////////////////////

    /*
    public function close()
    {
        stream_socket_shutdown($this->communicationChannel, STREAM_SHUT_RDWR);
    }
    */


    private function onReadCommand(int $cmd)
    {
        $agt = GrandAgent::get($this->agentId);

        BayLog::debug("%s receive command %d rd=%s", $this, $cmd, $this->rudder);
        switch ($cmd) {
            case GrandAgent::CMD_RELOAD_CERT:
                $agt->reloadCert();
                break;
            case GrandAgent::CMD_MEM_USAGE:
                $agt->printUsage();
                break;
            case GrandAgent::CMD_SHUTDOWN:
                $agt->reqShutdown();
                break;
            case GrandAgent::CMD_ABORT:
                $this->sendCommandToMonitor($agt, GrandAgent::CMD_OK, true);
                $agt->abort();
                return;
            default:
                BayLog::error("Unknown command: %d", $cmd);
        }

        //IOUtil::sendInt32($this->communicationChannel, GrandAgent::CMD_OK);
        $this->sendCommandToMonitor($agt, GrandAgent::CMD_OK, false);
    }

    private function sendCommandToMonitor(?GrandAgent $agt, int $cmd, bool $sync)
    {
        if($sync)
            IOUtil::writeInt32($this->rudder->key(), GrandAgent::CMD_OK);
        else {
            $buf = pack('N', $cmd);
            $agt->netMultiplexer->reqWrite($this->rudder, $buf, null, null, null);
        }
    }

    public function end()
    {
        BayLog::debug("%s end", $this);
        try {
            $this->sendCommandToMonitor(null, GrandAgent::CMD_CLOSE, true);
        }
        catch(\Exception $e) {
            BayLog::error_e($e, "%s Write error", $this->agent);
        }
        $this->close();
    }

    private function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->rudder->close();
        $this->closed = true;
    }

}
