<?php

namespace baykit\bayserver\docker\ajp;


use baykit\bayserver\BayLog;
use baykit\bayserver\docker\ajp\command\CmdData;
use baykit\bayserver\docker\ajp\command\CmdEndResponse;
use baykit\bayserver\docker\ajp\command\CmdForwardRequest;
use baykit\bayserver\docker\ajp\command\CmdGetBodyChunk;
use baykit\bayserver\docker\ajp\command\CmdSendBodyChunk;
use baykit\bayserver\docker\ajp\command\CmdSendHeaders;
use baykit\bayserver\docker\ajp\command\CmdShutdown;
use baykit\bayserver\protocol\CommandUnPacker;
use baykit\bayserver\protocol\Packet;
use baykit\bayserver\Sink;

class AjpCommandUnPacker extends CommandUnPacker {

    public $cmdHandler;

    public function __construct(AjpCommandHandler $handler)
    {
        $this->cmdHandler = $handler;
        $this->reset();
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
    }


    ////////////////////////////////////////////////////////////////////////////////
    // Implements CommandUnPacker
    ////////////////////////////////////////////////////////////////////////////////

    public function packetReceived(Packet $pkt): int
    {
        BayLog::debug("ajp:  packet received: type=%s datalen=%d", $pkt->type, $pkt->dataLen());

        // AJP intentionally bypasses the per-agent CommandStore pool: allocate
        // a fresh CmdXxx per incoming packet, matching Java's AjpCommandUnPacker
        // which also calls `new CmdXxx(...)` directly.
        switch ($pkt->type) {
            case AjpType::DATA:             $cmd = new CmdData(); break;
            case AjpType::FORWARD_REQUEST:  $cmd = new CmdForwardRequest(); break;
            case AjpType::SEND_HEADERS:     $cmd = new CmdSendHeaders(); break;
            case AjpType::END_RESPONSE:     $cmd = new CmdEndResponse(); break;
            case AjpType::SHUTDOWN:         $cmd = new CmdShutdown(); break;
            case AjpType::GET_BODY_CHUNK:   $cmd = new CmdGetBodyChunk(); break;
            case AjpType::SEND_BODY_CHUNK:
                $cmd = new CmdSendBodyChunk();
                $cmd->init($pkt->buf, $pkt->headerLen, $pkt->dataLen());
                break;
            default:
                throw new \Exception("Invalid packet". $pkt);
        }

        $cmd->unpack($pkt);
        return $cmd->handle($this->cmdHandler);
    }

    public function needData() : bool
    {
        return $this->cmdHandler->needData();
    }
}
