<?php

namespace baykit\bayserver\docker\built_in;

use baykit\bayserver\docker\Barge_Cargo;
use baykit\bayserver\tour\ReqContentHandler;
use baykit\bayserver\tour\Tour;

/**
 * Serves a single cached cargo straight from memory once the request
 * body has been fully consumed. Mirrors the anonymous ReqContentHandler
 * BuiltInCityDocker.enter uses upstream for the cache-hit path.
 */
class CargoFastPathHandler implements ReqContentHandler
{
    private Barge_Cargo $cargo;

    public function __construct(Barge_Cargo $cargo)
    {
        $this->cargo = $cargo;
    }

    public function onReadReqContent(Tour $tur, string $buf, int $start, int $len, ?callable $callback): void
    {
        // No-op: cargo response does not consume the request body.
    }

    public function onEndReqContent(Tour $tur): void
    {
        $this->cargo->headers()->copyTo($tur->res->headers);
        $tur->res->sendResHeaders(Tour::TOUR_ID_NOCHECK);
        $tur->res->setConsumeListener(function ($len, $resume) {});
        $tur->res->sendResContent(Tour::TOUR_ID_NOCHECK, $this->cargo->content(), 0, $this->cargo->length());
        $tur->res->endResContent(Tour::TOUR_ID_NOCHECK);
    }

    public function onAbortReq(Tour $tur): bool
    {
        return false;
    }
}
