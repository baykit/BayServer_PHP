<?php
namespace baykit\bayserver\docker\file;


use baykit\bayserver\BayLog;
use baykit\bayserver\tour\ReqContentHandler;
use baykit\bayserver\tour\Tour;


class FileContentHandler implements ReqContentHandler
{
    public $path;
    public $abortable;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->abortable = true;
    }

    ///////////////////////////////////////////////////////////////////////
    // Implements ReqContentHandler
    ///////////////////////////////////////////////////////////////////////

    public function onReadContent(Tour $tur, string $buf, int $start, int $len): void
    {
        BayLog::debug("%s onReadReqContent(Ignore) len=%d", $tur, $len);
    }

    public function onEndContent(Tour $tur): void
    {
        BayLog::debug("%s endReqContent", $tur);
        $tur->res->sendFile(Tour::TOUR_ID_NOCHECK, $this->path, $tur->res->charset, true);
        $this->abortable = false;
    }

    public function onAbort(Tour $tur): bool
    {
        BayLog::debug("%s onAbortReq aborted=%s", $tur, $this->abortable);
        return $this->abortable;
    }
}