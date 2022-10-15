<?php

namespace baykit\bayserver\docker\http\h2\command;

use baykit\bayserver\docker\http\h2\H2Command;
use baykit\bayserver\docker\http\h2\H2Flags;
use baykit\bayserver\docker\http\h2\H2Packet;
use baykit\bayserver\docker\http\h2\H2Type;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;



/**
 * HTTP/2 GoAway payload format
 *
 * +-+-------------------------------------------------------------+
 * |R|                  Last-Stream-ID (31)                        |
 * +-+-------------------------------------------------------------+
 * |                      Error Code (32)                          |
 * +---------------------------------------------------------------+
 * |                  Additional Debug Data (*)                    |
 * +---------------------------------------------------------------+
 *
 */
class CmdGoAway extends H2Command
{
    public $lastStreamId;
    public $errorCode;
    public $debugData;

    public function __construct(int $streamId, H2Flags $flags)
    {
        parent::__construct(H2Type::GOAWAY, $streamId, $flags);
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);
        $acc = $pkt->newDataAccessor();
        $val = $acc->getInt();
        $this->lastStreamId = H2Packet::extractInt31($val);
        $this->errorCode = $acc->getInt();
        $this->debugData = $acc->getBytes($pkt->dataLen() - $acc->pos);
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newDataAccessor();
        $acc->putInt($this->lastStreamId);
        $acc->putInt($this->errorCode);
        if($this->debugData != null)
            $acc->putBytes($this->debugData, 0, strlen($this->debugData));
        parent::pack($pkt);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleGoAway($this);
    }
}