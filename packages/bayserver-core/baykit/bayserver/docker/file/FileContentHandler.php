<?php

namespace baykit\bayserver\docker\file;


use baykit\bayserver\BayLog;
use baykit\bayserver\HttpException;
use baykit\bayserver\tour\ReqContentHandler;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\DirectoryException;
use baykit\bayserver\util\HttpStatus;


/**
 * Slimmed-down ReqContentHandler whose sole job is to call
 * TourRes.sendFile once the request body has been fully read.
 *
 * Mirrors the FileContentHandler: all the file-open /
 * mime / multiplexer plumbing moved into TourRes.sendFile, so this
 * class is a thin pass-through.
 */
class FileContentHandler implements ReqContentHandler
{
    public Tour $tour;
    public string $path;
    public ?string $charset;
    public bool $listFiles;
    public bool $abortable;

    public function __construct(Tour $tour, string $path, ?string $charset, bool $listFiles)
    {
        $this->tour = $tour;
        $this->path = $path;
        $this->charset = $charset;
        $this->listFiles = $listFiles;
        $this->abortable = true;
    }

    /////////////////////////////////////
    // Implements ReqContentHandler
    /////////////////////////////////////

    public function onReadReqContent(Tour $tur, string $buf, int $start, int $len, ?callable $callback): void
    {
        BayLog::debug("%s file:onReadContent(Ignore) len=%d", $tur, $len);
        $tur->req->consumed($tur->tourId, $len, $callback);
    }

    public function onEndReqContent(Tour $tur): void
    {
        BayLog::debug("%s file:endContent", $tur);
        $this->reqStartTour();
        $this->abortable = false;
    }

    public function onAbortReq(Tour $tur): bool
    {
        BayLog::debug("%s file:onAbort aborted=%s", $tur, $this->abortable);
        return $this->abortable;
    }

    /////////////////////////////////////
    // Custom methods
    /////////////////////////////////////

    public function reqStartTour() : void
    {
        BayLog::debug("%s reqStartTour", $this->tour);

        try {
            $this->tour->res->sendFile($this->path, $this->charset);
        }
        catch (DirectoryException $e) {
            $this->handleDirectory($this->tour, $this->path);
        }
        catch (HttpException $e) {
            throw $e;
        }
        catch (\Throwable $e) {
            BayLog::error_e($e);
            throw new HttpException(HttpStatus::INTERNAL_SERVER_ERROR, $this->path);
        }
    }

    private function handleDirectory(Tour $tur, string $path) : void
    {
        if ($this->listFiles) {
            // DirectoryTrain is not ported to PHP; mirror upstream's
            // listFiles=true control by returning 403 with a clear
            // message instead of silently producing nothing.
            throw new HttpException(HttpStatus::FORBIDDEN, "Directory listing not supported in PHP");
        }
        else {
            throw new HttpException(HttpStatus::FORBIDDEN, "Directory scan is prohibited");
        }
    }
}
