<?php

namespace baykit\bayserver\docker\http\h2\command;

use baykit\bayserver\docker\http\h2\H2Command;
use baykit\bayserver\docker\http\h2\H2Flags;
use baykit\bayserver\docker\http\h2\H2Type;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;



/**
 * HTTP/2 Data payload format
 *
 * +---------------+
 * |Pad Length? (8)|
 * +---------------+-----------------------------------------------+
 * |                            Data (*)                         ...
 * +---------------------------------------------------------------+
 * |                           Padding (*)                       ...
 * +---------------------------------------------------------------+
 */
class CmdData extends H2Command
{
    /**
     * This class refers external byte array, so this IS NOT mutable
     */
    public $start;
    public $length;
    public $data;

    public function __construct(int $streamId, ?H2Flags $flags, string &$data = null, int $start = 0, int $len = 0)
    {
        parent::__construct(H2Type::DATA, $streamId, $flags);
        $this->data = $data;
        $this->start = $start;
        $this->length = $len;
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);
        $this->data = $pkt->buf;
        $this->start = $pkt->headerLen;
        $this->length = $pkt->dataLen();
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newDataAccessor();
        if($this->flags->padded())
            throw new \InvalidArgumentException("Padding not supported");
        $acc->putBytes($this->data, $this->start, $this->length);
        parent::pack($pkt);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleData($this);
    }
}