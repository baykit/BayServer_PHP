<?php

namespace baykit\bayserver\protocol;

use baykit\bayserver\ship\Ship;
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

    public function post(Ship $ship, Packet $pkt, ?callable $lsnr) : void
    {
        $ship->transporter->reqWrite(
            $ship->rudder,
            substr($pkt->buf, 0, $pkt->bufLen),
            null,
            $pkt,
            $lsnr);
    }
}