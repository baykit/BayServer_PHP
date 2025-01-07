<?php
namespace baykit\bayserver\common;


use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\BayLog;
use baykit\bayserver\tour\ReqContentHandler;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\Headers;

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
        return $this->warpShip . " wtur#" . $this->warpId;
    }


    /////////////////////////////////////////
    // Implements ContentHandler
    /////////////////////////////////////////
    public function onReadReqContent(Tour $tur, string $buf, int $start, int $len, ?callable $callback): void
    {
        BayLog::debug("%s onReadReqContent tur=%s len=%d", $this->warpShip, $tur, $len);
        $this->warpShip->checkShipId($this->warpShipId);
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

            $this->warpShip->warpHandler()->sendReqContents(
                $tur,
                $buf,
                $start + $pos,
                $postLen,
                $callback);
        }
    }

    public function onEndReqContent(Tour $tur): void
    {
        BayLog::debug("%s endReqContent tur=%s", $this->warpShip, $tur);
        $this->warpShip->checkShipId($this->warpShipId);
        $this->warpShip->warpHandler()->sendEndReq($tur, false, function ()  {
            $agt = GrandAgent::get($this->warpShip->agentId);
            $agt->netMultiplexer->reqRead($this->warpShip->rudder);
        });
    }

    public function onAbortReq(Tour $tur): bool
    {
        BayLog::debug("%s onAbortReq tur=%s", $this->warpShip, $tur);
        $this->warpShip->checkShipId($this->warpShipId);
        $this->warpShip->abort($this->warpShipId);
        return false; // not aborted immediately
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