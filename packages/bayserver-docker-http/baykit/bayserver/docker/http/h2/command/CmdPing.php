<?php

namespace baykit\bayserver\docker\http\h2\command;

use baykit\bayserver\docker\http\h2\H2Command;
use baykit\bayserver\docker\http\h2\H2Flags;
use baykit\bayserver\docker\http\h2\H2Type;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;
use baykit\bayserver\util\StringUtil;


class CmdPing extends H2Command
{
    public $opaqueData;

    public function __construct(int $streamId, H2Flags $flags)
    {
        parent::__construct(H2Type::PING, $streamId, $flags);
        $this->opaqueData = StringUtil::allocate(8);
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);
        $acc = $pkt->newDataAccessor();
        $this->opaqueData = $acc->getBytes(8);
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newDataAccessor();
        $acc->putBytes($this->opaqueData);
        parent::pack($pkt);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handlePing($this);
    }
}