<?php

namespace baykit\bayserver\docker\fcgi;

use baykit\bayserver\protocol\Command;
use baykit\bayserver\protocol\Packet;


abstract class FcgCommand extends Command {

    public $reqId;

    public function __construct(int $type, int $reqId)
    {
        parent::__construct($type);
        $this->reqId = $reqId;
    }

    public function unpack(Packet $pkt) : void
    {
        $this->reqId = $pkt->reqId;
    }

    /**
     * super class method must be called from last line of override method since header cannot be packed before data is constructed
     */
    public function pack(Packet $pkt) : void
    {
        $pkt->reqId = $this->reqId;
        $this->packHeader($pkt);
    }

    public function packHeader(FcgPacket $pkt) : void
    {
        $acc = $pkt->newHeaderAccessor();
        $acc->putByte($pkt->version);
        $acc->putByte($pkt->type);
        $acc->putShort($pkt->reqId);
        $acc->putShort($pkt->dataLen());
        $acc->putByte(0);  // paddinglen
        $acc->putByte(0); // reserved
    }
}