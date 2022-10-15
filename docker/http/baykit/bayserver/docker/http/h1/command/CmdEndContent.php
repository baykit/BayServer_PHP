<?php

namespace baykit\bayserver\docker\http\h1\command;

use baykit\bayserver\docker\http\h1\H1Command;
use baykit\bayserver\docker\http\h1\H1Type;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;

class CmdEndContent extends H1Command
{
    public function __construct()
    {
        parent::__construct(H1Type::END_CONTENT);
    }

    public function unpack(Packet $pkt): void
    {
    }

    public function pack(Packet $pkt): void
    {
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleEndContent($this);
    }
}