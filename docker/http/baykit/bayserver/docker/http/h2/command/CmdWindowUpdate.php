<?php

namespace baykit\bayserver\docker\http\h2\command;

use baykit\bayserver\BayLog;
use baykit\bayserver\docker\http\h2\H2Command;
use baykit\bayserver\docker\http\h2\H2Flags;
use baykit\bayserver\docker\http\h2\H2Packet;
use baykit\bayserver\docker\http\h2\H2Type;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;

/**
 * HTTP/2 Window Update payload format
 *
 * +-+-------------------------------------------------------------+
 * |R|              Window Size Increment (31)                     |
 * +-+-------------------------------------------------------------+
 */
class CmdWindowUpdate extends H2Command
{
    public $windowSizeIncrement;

    public function __construct(int $streamId, H2Flags $flags = null)
    {
        parent::__construct(H2Type::WINDOW_UPDATE, $streamId, $flags);
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);
        $acc = $pkt->newDataAccessor();
        $val = $acc->getInt();
        $this->windowSizeIncrement = H2Packet::extractInt31($val);
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newDataAccessor();
        $acc->putInt(H2Packet::consolidateFlagAndInt32(0, $this->windowSizeIncrement));

        BayLog::debug("Pack windowUpdate size=%d", $this->windowSizeIncrement);
        parent::pack($pkt);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleWindowUpdate($this);
    }
}