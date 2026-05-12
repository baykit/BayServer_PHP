<?php
namespace baykit\bayserver\tour;


use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\util\DataConsumeListener;
use baykit\bayserver\util\Reusable;

interface TourHandler extends Reusable
{
    /**
     * Send HTTP headers to client
     */
    function sendResHeaders(Tour $tur): void;

    /**
     * Send Contents to client
     */
    function sendResContent(Tour $tur, string $bytes, int $ofs, int $len, ?callable $callback): void;

    /**
     * Direct Boarding transfer (sendfile fast-path).
     * Forwards a file rudder + (offset, length) onto the ship's write queue.
     */
    function transferContent(Tour $tur, Rudder $fileRd, int $ofs, int $len, ?callable $callback): void;

    /**
     * Send end of contents to client.
     */
    function sendEndTour(Tour $tur, ?callable $callback): void;

    /**
     * Send protocol error to client
     */
    function onProtocolError(ProtocolException $e): bool;

}

