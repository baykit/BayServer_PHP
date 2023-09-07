<?php

namespace baykit\bayserver\protocol;

use baykit\bayserver\util\Postman;
use baykit\bayserver\util\Reusable;

class PacketPacker implements Reusable
{

    /////////////////////////////////////////////////////////////////////////////////
    // Implements Reusable
    /////////////////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
    }

    public function post(Postman $pm, Packet $pkt, callable $lsnr) : void
    {
        $pm->post(substr($pkt->buf, 0, $pkt->bufLen), null, $pkt, $lsnr);
    }

    public function flush(Postman $pm) : void
    {
        $pm->flush();
    }

    public function end(Postman $pm) : void
    {
        $pm->postEnd();
    }
}