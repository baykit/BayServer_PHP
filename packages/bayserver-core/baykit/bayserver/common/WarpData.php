<?php
namespace baykit\bayserver\common;


use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\BayLog;
use baykit\bayserver\tour\ReqContentHandler;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\IOException;

class WarpData implements ReqContentHandler
{
    public $warpShip = null;
    public $warpShipId;
    public $warpId;
    public $reqHeaders;
    public $resHeaders;
    public $started = false;
    public $ended = false;

    public function __construct(WarpShip $warpShip, int $warpId)
    {
        $this->reqHeaders = new Headers();
        $this->resHeaders = new Headers();
        $this->warpShip = $warpShip;
        $this->warpShipId = $warpShip->id();
        $this->warpId = $warpId;
    }

    public function __toString() : string{
        return $this->warpShip. " wtur#". $this->warpId;
    }


    /////////////////////////////////////////
    // Implements ContentHandler
    /////////////////////////////////////////
    public function onReadReqContent(Tour $tur, string $buf, int $start, int $len, ?callable $callback): void
    {
        BayLog::debug("%s onReadReqContent tur=%s len=%d", $this->warpShip, $tur, $len);
        // STABILITY FIX: the shared warp ship may
        // have been recycled out from under us by a sibling tour in H2
        // multiplex. Drop with a bounded IOException so the caller
        // cleans up the front-end tour normally instead of taking down
        // the agent with Sink.
        if ($this->isStaleShip()) {
            if ($callback !== null) $callback($len, true);
            throw new IOException($this->warpShip. " warp connection has been closed");
        }
        $maxLen = $this->warpShip->protocolHandler->maxReqPacketDataSize();
        for($pos = 0; $pos < $len; $pos += $maxLen) {
            $postLen = $len - $pos;
            if($postLen > $maxLen) {
                $postLen = $maxLen;
            }

            $turId = $tur->id();
            $callback = function () use ($len, $turId, $tur, $callback) {
                $tur->req->consumed($turId, $len, $callback);
            };

            if (!$this->started) {
                # The buffer will become corrupted due to reuse.
                $newBuf = $buf;
                $buf = &$newBuf;
            }

            $this->warpShip->warpHandler()->sendReqContents($tur,
                $buf,
                $start + $pos,
                $postLen,
                $callback);
        }
    }

    public function onEndReqContent(Tour $tur): void
    {
        BayLog::debug("%s endReqContent tur=%s", $this->warpShip, $tur);
        if ($this->isStaleShip()) {
            throw new IOException($this->warpShip. " warp connection has been closed");
        }
        $this->warpShip->warpHandler()->sendEndReq($tur, false, function ()  {
            $agt = GrandAgent::get($this->warpShip->agentId);
            $agt->netMultiplexer->reqRead($this->warpShip->rudder);
        });
    }

    public function onAbortReq(Tour $tur): bool
    {
        BayLog::debug("%s onAbortReq tur=%s", $this->warpShip, $tur);
        // STABILITY FIX: warp ship may already be
        // gone (recycled by sibling tour's close in H2 multiplex).
        // Treat the abort as completed so the inbound side can return
        // the tour now.
        if ($this->isStaleShip()) {
            return true;
        }
        $this->warpShip->abort($this->warpShipId);
        return false; // not aborted immediately
    }

    /** True if the underlying warp ship has been recycled out from under us. */
    private function isStaleShip(): bool
    {
        return !$this->warpShip->initialized || $this->warpShip->id() !== $this->warpShipId;
    }


    public function start() : void
    {
        if(!$this->started) {
            BayLog::debug("%s Start Warp tour", $this);
            $this->warpShip->flush();
            $this->started = true;
        }
    }

    public static function get(Tour $tur): WarpData
    {
        return $tur->req->contentHandler;
    }

}