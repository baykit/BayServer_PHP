<?php
namespace baykit\bayserver\common;


use baykit\bayserver\tour\Tour;

interface WarpHandler
{
    public function nextWarpId() : int;

    /**
     * Maximum number of tours that can ride a single WarpShip before
     * the warp pool opens another backend connection. 1 = single-tour
     * (HTTP/1.1 keep-alive style); higher = stream-multiplexed (HTTP/2).
     * Mirrors WarpHandler.maxMultiplexedTours.
     */
    public function maxMultiplexedTours() : int;

    public function newWarpData(int $warpId) : WarpData;

    public function sendReqHeaders(Tour $tur) : void;

    public function sendReqContents(Tour $tur, string $buf, int $start, int $len, ?callable $callback) : void;

    public function sendEndReq(Tour $tur, bool $keepAlive, ?callable $callback): void;

    /**
     * Verify if protocol is allowed
     */
    public function verifyProtocol(string $protocol) : void;
}