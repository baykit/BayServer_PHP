<?php

namespace baykit\bayserver\docker\http\h2\command;

use baykit\bayserver\docker\http\h2\H2Command;
use baykit\bayserver\docker\http\h2\H2Flags;
use baykit\bayserver\docker\http\h2\H2Type;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;

/**
 * HTTP/2 RstStream payload format
 *
 +---------------------------------------------------------------+
 |                        Error Code (32)                        |
 +---------------------------------------------------------------+
 *
 */
class CmdRstStream extends H2Command
{
    public $errorCode;

    public function __construct(int $streamId, H2Flags $flags = null)
    {
        parent::__construct(H2Type::RST_STREAM, $streamId, $flags);
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);
        $acc = $pkt->newDataAccessor();
        $this->errorCode = $acc->getInt();
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newDataAccessor();
        $acc->putInt($this->errorCode);
        parent::pack($pkt);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleRstStream($this);
    }
}