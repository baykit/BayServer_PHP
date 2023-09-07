<?php

namespace baykit\bayserver\docker\http\h2\command;

use baykit\bayserver\docker\http\h2\H2Command;
use baykit\bayserver\docker\http\h2\H2Flags;
use baykit\bayserver\docker\http\h2\H2Packet;
use baykit\bayserver\docker\http\h2\H2Type;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;

/**
 * HTTP/2 Priority payload format
 *
 * +-+-------------------------------------------------------------+
 * |E|                  Stream Dependency (31)                     |
 * +-+-------------+-----------------------------------------------+
 * |   Weight (8)  |
 * +-+-------------+
 *
 */
class CmdPriority extends H2Command
{
    public $weight;
    public $excluded;
    public $streamDependency;

    public function __construct(int $streamId, H2Flags $flags)
    {
        parent::__construct(H2Type::PRIORITY, $streamId, $flags);
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);
        $acc = $pkt->newDataAccessor();
        $val = $acc->getInt();
        $this->excluded = H2Packet::extractFlag($val) == 1;
        $this->streamDependency = H2Packet::extractInt31($val);

        $this->weight = $acc->getByte();
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newDataAccessor();
        $acc->putInt(H2Packet::makeStreamDependency32($this->excluded, $this->streamDependency));
        $acc->putByte($this->weight);
        parent::pack($pkt);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handlePriority($this);
    }
}