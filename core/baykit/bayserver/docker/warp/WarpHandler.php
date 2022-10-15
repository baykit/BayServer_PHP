<?php
namespace baykit\bayserver\docker\warp;


use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\DataConsumeListener;

interface WarpHandler
{
    public function nextWarpId() : int;

    public function newWarpData(int $warpId) : WarpData;

    public function postWarpHeaders(Tour $tur) : void;

    public function postWarpContents(Tour $tur, string $buf, int $start, int $len, callable $lis) : void;

    public function postWarpEnd(Tour $tur) : void;

    /**
     * Verify if protocol is allowed
     */
    public function verifyProtocol(string $protocol) : void;
}