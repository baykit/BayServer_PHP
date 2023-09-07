<?php
namespace baykit\bayserver\docker\base;

use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\tour\Tour;


interface InboundHandler
{
    /**
     * Send protocol error
     */
    public function sendReqProtocolError(ProtocolException $e) : bool;

    /**
     * Send HTTP headers to client
     */
    public function sendResHeaders(Tour $tur) : void;

    /**
     * Send Contents to client
     */
    public function sendResContent(Tour $tur, string $bytes, int $ofs, int $len, ?callable $callback) : void;

    /**
     * Send end of contents to client.
     * sendEnd cannot refer Tour instance because it is discarded before call.
     */
    public function sendEndTour(Tour $tur, bool $keepAlive, callable $callback) : void;


}