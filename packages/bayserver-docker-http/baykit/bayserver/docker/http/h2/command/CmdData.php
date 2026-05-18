<?php

namespace baykit\bayserver\docker\http\h2\command;

use baykit\bayserver\docker\http\h2\H2Command;
use baykit\bayserver\docker\http\h2\H2Flags;
use baykit\bayserver\docker\http\h2\H2Type;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;
use baykit\bayserver\protocol\ProtocolException;



/**
 * HTTP/2 Data payload format
 *
 * +---------------+
 * |Pad Length? (8)|
 * +---------------+-----------------------------------------------+
 * |                            Data (*)...
 * +---------------------------------------------------------------+
 * |                           Padding (*)...
 * +---------------------------------------------------------------+
 */
class CmdData extends H2Command
{
    /**
     * This class refers external byte array, so this IS NOT mutable
     */
    public $start = 0;
    public $length = 0;
    public $data = null;

    public function __construct()
    {
        parent::__construct(H2Type::DATA);
    }

    public function setData(?string &$data, int $start, int $len) : void
    {
        $this->data = $data;
        $this->start = $start;
        $this->length = $len;
    }

    public function reset() : void
    {
        $this->start = 0;
        $this->length = 0;
        $this->data = null;
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);
        $acc = $pkt->newDataAccessor();

        $padLength = 0;
        if ($pkt->flags->padded())
            $padLength = $acc->getByte();

        $this->data = $pkt->buf;
        $this->start = $pkt->headerLen + $acc->pos;
        $this->length = $pkt->dataLen() - $acc->pos - $padLength;

        // RFC 7540 § 6.1: padding length must leave at least one
        // octet of data.
        if ($this->length < 0)
            throw new ProtocolException("DATA pad length exceeds payload: pad=$padLength");
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