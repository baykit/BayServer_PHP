<?php

namespace baykit\bayserver\docker\fcgi\command;


use baykit\bayserver\docker\fcgi\FcgCommand;
use baykit\bayserver\docker\fcgi\FcgPacket;
use baykit\bayserver\protocol\Packet;


/**
 * FCGI spec
 *   http://www.mit.edu/~yandros/doc/specs/fcgi-spec.html
 *
 * StdIn/StdOut/StdErr command format
 *   raw data
 */
abstract class InOutCommandBase extends FcgCommand
{
    const MAX_DATA_LEN = FcgPacket::MAXLEN - FcgPacket::PREAMBLE_SIZE;

    /**
     * This class refers external byte array, so this IS NOT mutable
     */
    public $start;
    public $length;
    public $data;

    public function __construct(int $type, int $reqId, string $data=null, int $start=0, int $length=0)
    {
        parent::__construct($type, $reqId);
        $this->data = $data;
        $this->start = $start;
        $this->length = $length;
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);
        $this->start = $pkt->headerLen;
        $this->length = $pkt->dataLen();
        $this->data = $pkt->buf;
    }

    public function pack(Packet $pkt): void
    {
        if($this->data != null && strlen($this->data) > 0) {
            $acc = $pkt->newDataAccessor();
            $acc->putBytes($this->data, $this->start, $this->length);
        }

        // must be called from last line
        parent::pack($pkt);
    }
}