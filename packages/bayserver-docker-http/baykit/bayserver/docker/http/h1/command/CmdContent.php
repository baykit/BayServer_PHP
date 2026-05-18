<?php

namespace baykit\bayserver\docker\http\h1\command;

use baykit\bayserver\docker\http\h1\H1Command;
use baykit\bayserver\docker\http\h1\H1Type;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;

class CmdContent extends H1Command
{
    public ?string $buf = null;
    public int $start = 0;
    public int $len = 0;

    public function __construct()
    {
        parent::__construct(H1Type::CONTENT);
    }

    public function init(?string $buf, int $start, int $length) : void
    {
        $this->buf = $buf;
        $this->start = $start;
        $this->len = $length;
    }

    ///////////////////////////////////////////////
    // Implements Reusable
    ///////////////////////////////////////////////

    public function reset() : void
    {
        $this->buf = null;
        $this->start = 0;
        $this->len = 0;
    }

    ///////////////////////////////////////////////
    // Implements Command
    ///////////////////////////////////////////////

    public function unpack(Packet $pkt): void
    {
        $this->buf = $pkt->buf;
        $this->start = $pkt->headerLen;
        $this->len = $pkt->dataLen();
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newDataAccessor();
        $acc->putBytes($this->buf, $this->start, $this->len);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleContent($this);
    }
}