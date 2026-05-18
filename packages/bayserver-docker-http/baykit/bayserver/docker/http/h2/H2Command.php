<?php

namespace baykit\bayserver\docker\http\h2;

use baykit\bayserver\protocol\Command;
use baykit\bayserver\protocol\Packet;


abstract class H2Command extends Command {

    public $flags;
    public $streamId = 0;

    public function __construct(int $type)
    {
        parent::__construct($type);
        $this->flags = new H2Flags();
    }

    public function init(int $streamId, H2Flags $flags = null) : void
    {
        $this->streamId = $streamId;
        if ($flags == null)
            $this->flags = new H2Flags();
        else
            $this->flags = $flags;
    }

    public function unpack(Packet $pkt) : void
    {
        $this->streamId = $pkt->streamId;
        $this->flags = $pkt->flags;
    }

    public function pack(Packet $pkt) : void
    {
        $pkt->streamId = $this->streamId;
        $pkt->flags = $this->flags;
        $pkt->packHeader();
    }
}