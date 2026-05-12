<?php

namespace baykit\bayserver\docker\built_in;

use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\docker\Barge_Cargo;
use baykit\bayserver\tour\ReqContentHandler;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\IOException;

/**
 * Serves a single cached cargo straight from memory once the request
 * body has been fully consumed. Mirrors the anonymous ReqContentHandler
 * BuiltInCityDocker.enter uses upstream for the cache-hit path.
 *
 * Chunks the response when the cargo is larger than the harbor ship
 * buffer so a single big cached file cannot blow past back-pressure
 *.
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
        // Mirror: even when the cargo handler doesn't care
        // about the request body, the consumption counter must still
        // advance or back-pressure on the request side deadlocks.
        $tur->req->consumed(Tour::TOUR_ID_NOCHECK, $len, $callback);
    }

    public function onEndReqContent(Tour $tur): void
    {
        $this->cargo->headers()->copyTo($tur->res->headers);
        $tur->res->sendResHeaders(Tour::TOUR_ID_NOCHECK);

        if ($this->cargo->length() <= BayServer::$harbor->shipBufferSize()) {
            // Small cached content: send in one shot.
            $tur->res->setConsumeListener(function ($len, $resume) {});
            $tur->res->sendResContent(Tour::TOUR_ID_NOCHECK, $this->cargo->content(), 0, $this->cargo->length());
            $tur->res->endResContent(Tour::TOUR_ID_NOCHECK);
        } else {
            // Larger than ship buffer: chunked with consume-listener
            // backpressure so a slow client cannot pin the buffer.
            $this->sendCachedContentChunked($tur);
        }
    }

    public function onAbortReq(Tour $tur): bool
    {
        return false;
    }

    private function sendCachedContentChunked(Tour $tur) : void
    {
        $cgo = $this->cargo;
        $turId = $tur->tourId;
        $chunkSize = $tur->ship->protocolHandler->maxResPacketDataSize();
        $pos = 0;
        // Re-entry guard. sendResContent may complete synchronously
        // for a chunk that fits in the write queue, in which case the
        // consume listener fires inside sendChunks itself -- if the
        // listener then calls sendChunks again, each chunk adds a
        // stack frame and 100KB+ responses overflow the stack.
        // Mirrors
        $sending = false;

        $sendChunks = function () use ($tur, $cgo, $chunkSize, &$pos) {
            $available = true;
            while ($pos < $cgo->length() && $available) {
                $len = $chunkSize;
                if ($len > $cgo->length() - $pos) {
                    $len = $cgo->length() - $pos;
                }
                $available = $tur->res->sendResContent(Tour::TOUR_ID_NOCHECK, $cgo->content(), $pos, $len);
                $pos += $len;
            }
            if ($pos >= $cgo->length()) {
                $tur->res->endResContent(Tour::TOUR_ID_NOCHECK);
            }
        };

        $tur->res->setConsumeListener(function ($len, $resume) use ($sendChunks, &$sending) {
            if (!$resume || $sending) {
                return;
            }
            $sending = true;
            try {
                $sendChunks();
            } catch (IOException $e) {
                BayLog::error_e($e);
            } finally {
                $sending = false;
            }
        });

        $sending = true;
        try {
            $sendChunks();
        } finally {
            $sending = false;
        }
    }
}
