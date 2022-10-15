<?php

namespace baykit\bayserver\docker\http\h1\command;

use baykit\bayserver\docker\http\h1\H1Command;
use baykit\bayserver\docker\http\h1\H1Type;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;

class CmdContent extends H1Command
{
    public $buf;
    public $start;
    public $len;

    public function __construct(string $buf=null, int $start=null, int $length=null)
    {
        parent::__construct(H1Type::CONTENT);
        $this->buf = $buf;
        $this->start = $start;
        $this->len = $length;
    }

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