<?php

namespace baykit\bayserver\docker\http\h2\command;

use baykit\bayserver\docker\http\h2\H2Command;
use baykit\bayserver\docker\http\h2\H2Flags;
use baykit\bayserver\docker\http\h2\H2Packet;
use baykit\bayserver\docker\http\h2\H2Type;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;
use baykit\bayserver\protocol\ProtocolException;

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
    public $weight = 0;
    public $excluded = false;
    public $streamDependency = 0;

    public function __construct()
    {
        parent::__construct(H2Type::PRIORITY);
    }

    public function reset() : void
    {
        $this->weight = 0;
        $this->excluded = false;
        $this->streamDependency = 0;
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);
        $acc = $pkt->newDataAccessor();
        $val = $acc->getInt();
        $this->excluded = H2Packet::extractFlag($val) == 1;
        $this->streamDependency = H2Packet::extractInt31($val);

        $this->weight = $acc->getByte();

        // RFC 7540 § 5.3.1: a stream MUST NOT depend on itself.
        // Mirrors
        if ($this->streamDependency === $this->streamId)
            throw new ProtocolException("PRIORITY stream depends on itself: {$this->streamId}");
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