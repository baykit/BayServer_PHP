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
    public $opaqueData = null;

    public function __construct()
    {
        parent::__construct(H2Type::PING);
        $this->opaqueData = StringUtil::allocate(8);
    }

    public function setOpaqueData(string $opaqueData) : void
    {
        // Mirror: echo the received 8-byte opaque_data back
        // so PING-ACK round-trips. The previous constructor silently
        // dropped the argument and always emitted zeros, which is an
        // RFC 7540 §6.7 conformance failure.
        $this->opaqueData = $opaqueData;
    }

    public function reset() : void
    {
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