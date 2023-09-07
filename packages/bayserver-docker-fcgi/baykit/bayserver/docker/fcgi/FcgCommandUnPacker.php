<?php

namespace baykit\bayserver\docker\fcgi;


use baykit\bayserver\docker\fcgi\command\CmdBeginRequest;
use baykit\bayserver\docker\fcgi\command\CmdEndRequest;
use baykit\bayserver\docker\fcgi\command\CmdParams;
use baykit\bayserver\docker\fcgi\command\CmdStdErr;
use baykit\bayserver\docker\fcgi\command\CmdStdIn;
use baykit\bayserver\docker\fcgi\command\CmdStdOut;
use baykit\bayserver\protocol\CommandUnPacker;
use baykit\bayserver\protocol\Packet;

class FcgCommandUnPacker extends CommandUnPacker
{
    private $cmdHandler;

    public function __construct(FcgCommandHandler $handler)
    {
        $this->cmdHandler = $handler;
        $this->reset();
    }

    public function packetReceived(Packet $pkt): int
    {
        switch ($pkt->type) {
            case FcgType::BEGIN_REQUEST:
                $cmd = new CmdBeginRequest($pkt->reqId);
                break;

            case FcgType::END_REQUEST:
                $cmd = new CmdEndRequest($pkt->reqId);
                break;

            case FcgType::PARAMS:
                $cmd = new CmdParams($pkt->reqId);
                break;

            case FcgType::STDIN:
                $cmd = new CmdStdIn($pkt->reqId);
                break;

            case FcgType::STDOUT:
                $cmd = new CmdStdOut($pkt->reqId);
                break;

            case FcgType::STDERR:
                $cmd = new CmdStdErr($pkt->reqId);
                break;



            default:
                throw new \Exception("Invalid packet" . $pkt);
        }

        $cmd->unpack($pkt);
        return $cmd->handle($this->cmdHandler);

    }

    public function reset(): void
    {
    }
}