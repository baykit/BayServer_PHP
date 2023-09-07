<?php

namespace baykit\bayserver\docker\http\h2\command;

use baykit\bayserver\docker\http\h2\H2Command;
use baykit\bayserver\docker\http\h2\H2Flags;
use baykit\bayserver\docker\http\h2\H2Type;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;

/**
 * HTTP/2 Setting payload format
 *
 * +-------------------------------+
 * |       Identifier (16)         |
 * +-------------------------------+-------------------------------+
 * |                        Value (32)                             |
 * +---------------------------------------------------------------+
 *
 */
class CmdSettings_Item
{
    public $id;
    public $value;

    public function __construct(int $id, int $value)
    {
        $this->id = $id;
        $this->value = $value;
    }
}

class CmdSettings extends H2Command
{
    const HEADER_TABLE_SIZE = 0x1;
    const ENABLE_PUSH = 0x2;
    const MAX_CONCURRENT_STREAMS = 0x3;
    const INITIAL_WINDOW_SIZE = 0x4;
    const MAX_FRAME_SIZE = 0x5;
    const MAX_HEADER_LIST_SIZE = 0x6;

    const INIT_HEADER_TABLE_SIZE = 4096;
    const INIT_ENABLE_PUSH = 1;
    const INIT_MAX_CONCURRENT_STREAMS = -1;
    const INIT_INITIAL_WINDOW_SIZE = 65535;
    const INIT_MAX_FRAME_SIZE = 16384;
    const INIT_MAX_HEADER_LIST_SIZE = -1;

    public $items = [];

    public function __construct(int $streamId, H2Flags $flags = null)
    {
        parent::__construct(H2Type::SETTINGS, $streamId, $flags);
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);
        if($this->flags->ack()) {
            return;
        }
        $acc = $pkt->newDataAccessor();
        $pos = 0;
        while($pos < $pkt->dataLen()) {
            $id = $acc->getShort();
            $value = $acc->getInt();
            $this->items[] = new CmdSettings_Item($id, $value);
            $pos += 6;
        }
    }

    public function pack(Packet $pkt): void
    {
        if($this->flags->ack()) {
            // not pack payload
        }
        else {
            $acc = $pkt->newDataAccessor();
            foreach ($this->items as $item) {
                $acc->putShort($item->id);
                $acc->putInt($item->value);
            }
        }
        parent::pack($pkt);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleSettings($this);
    }
}