<?php

namespace baykit\bayserver\docker\http\h2\command;

use baykit\bayserver\docker\http\h2\H2Command;
use baykit\bayserver\docker\http\h2\H2Flags;
use baykit\bayserver\docker\http\h2\H2Packet;
use baykit\bayserver\docker\http\h2\H2Type;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;
use baykit\bayserver\util\StringUtil;

/**
 * Preface is dummy command and packet
 *
 *   packet is not in frame format but raw data: "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n"
 */
class CmdPreface extends H2Command
{
    public $PREFACE_BYTES = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

    public $protocol;

    public function __construct(int $streamId, H2Flags $flags)
    {
        parent::__construct(H2Type::PREFACE, $streamId, $flags);
    }

    public function unpack(Packet $pkt): void
    {
        $acc = $pkt->newDataAccessor();
        $prefaceData = $acc->getBytes(24);
        $this->protocol = substr($prefaceData, 6, 8);
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newDataAccessor();
        $acc->putBytes($this->PREFACE_BYTES);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handlePreface($this);
    }
}