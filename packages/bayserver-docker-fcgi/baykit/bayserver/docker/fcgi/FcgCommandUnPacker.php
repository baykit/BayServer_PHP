<?php

namespace baykit\bayserver\docker\fcgi;


use baykit\bayserver\protocol\CommandStore;
use baykit\bayserver\protocol\CommandUnPacker;
use baykit\bayserver\protocol\Packet;

class FcgCommandUnPacker extends CommandUnPacker
{
    private $cmdHandler;
    public CommandStore $store;

    public function __construct(FcgCommandHandler $handler, CommandStore $store)
    {
        $this->cmdHandler = $handler;
        $this->store = $store;
        $this->reset();
    }

    public function packetReceived(Packet $pkt): int
    {
        switch ($pkt->type) {
            case FcgType::BEGIN_REQUEST:
            case FcgType::END_REQUEST:
            case FcgType::PARAMS:
            case FcgType::STDIN:
            case FcgType::STDOUT:
            case FcgType::STDERR:
                $cmd = $this->store->rent($pkt->type);
                $cmd->init($pkt->reqId);
                break;

            default:
                throw new \Exception("Invalid packet". $pkt);
        }

        $cmd->unpack($pkt);
        $res = $cmd->handle($this->cmdHandler);
        $this->store->Return($cmd);
        return $res;
    }

    public function reset(): void
    {
    }
}
