<?php

namespace baykit\bayserver\docker\http\h1;


use baykit\bayserver\BayLog;
use baykit\bayserver\docker\http\h1\command\CmdContent;
use baykit\bayserver\docker\http\h1\command\CmdHeader;
use baykit\bayserver\protocol\CommandStore;
use baykit\bayserver\protocol\CommandUnPacker;
use baykit\bayserver\protocol\Packet;
use baykit\bayserver\Sink;

class H1CommandUnPacker extends CommandUnPacker {

    public $serverMode;
    public $cmdHandler;
    public CommandStore $store;

    public function __construct(H1CommandHandler $handler, CommandStore $store, bool $srvMode)
    {
        $this->cmdHandler = $handler;
        $this->store = $store;
        $this->serverMode = $srvMode;
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
        BayLog::debug("h1: read packet type=%d length=%d", $pkt->type, $pkt->dataLen());

        if ($pkt->type == H1Type::HEADER) {
            $cmd = $this->store->rent(H1Type::HEADER);
            $cmd->init($this->serverMode);
        }
        elseif ($pkt->type == H1Type::CONTENT) {
            $cmd = $this->store->rent(H1Type::CONTENT);
        }
        else {
            $this->reset();
            throw new Sink("IllegalState");
        }

        $cmd->unpack($pkt);
        $res = $cmd->handle($this->cmdHandler);
        $this->store->Return($cmd);
        return $res;
    }

    public function reqFinished() : bool
    {
        return $this->cmdHandler->reqFinished();
    }
}