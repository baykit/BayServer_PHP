<?php

namespace baykit\bayserver\docker\ajp;

use baykit\bayserver\protocol\Command;
use baykit\bayserver\protocol\Packet;


abstract class AjpCommand extends Command {

    public $toServer;

    public function __construct(int $type, bool $toServer)
    {
        parent::__construct($type);
        $this->toServer = $toServer;
    }

    public function unpack(Packet $pkt) : void
    {
        if($pkt->type != $this->type)
            throw new \InvalidArgumentException();
        $this->toServer = $pkt->toServer;
    }

    /**
     * super class method must be called from last line of override method since header cannot be packed before data is constructed
     */
    public function pack(Packet $pkt) : void
    {
        if($pkt->type != $this->type)
            throw new \InvalidArgumentException();
        $pkt->toServer = $this->toServer;
        $this->packHeader($pkt);
    }

    public function packHeader(AjpPacket $pkt) : void
    {
        $acc = $pkt->newAjpHeaderAccessor();
        if($pkt->toServer) {
            $acc->putByte(0x12);
            $acc->putByte(0x34);
        }
        else {
            $acc->putByte(ord('A'));
            $acc->putByte(ord('B'));
        }
        $acc->putByte(($pkt->dataLen() >> 8) & 0xff);
        $acc->putByte($pkt->dataLen() & 0xff);
    }
}