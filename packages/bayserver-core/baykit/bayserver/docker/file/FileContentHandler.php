<?php
namespace baykit\bayserver\docker\file;


use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\multiplexer\PlainTransporter;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\common\RudderState;
use baykit\bayserver\docker\Harbor;
use baykit\bayserver\HttpException;
use baykit\bayserver\rudder\StreamRudder;
use baykit\bayserver\Sink;
use baykit\bayserver\tour\ReqContentHandler;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\Mimes;
use baykit\bayserver\util\StringUtil;


class FileContentHandler implements ReqContentHandler
{
    public $path;
    public $abortable;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->abortable = true;
    }

    /////////////////////////////////////
    // Implements ReqContentHandler
    /////////////////////////////////////

    public function onReadReqContent(Tour $tur, string $buf, int $start, int $len, ?callable $callback): void
    {
        BayLog::debug("%s onReadReqContent(Ignore) len=%d", $tur, $len);
        $tur->req->consumed($tur->tourId, $len, $callback);
    }

    public function onEndReqContent(Tour $tur): void
    {
        BayLog::debug("%s endReqContent", $tur);
        $this->sendFileAsync($tur, $this->path, $tur->res->charset);
        $this->abortable = false;
    }

    public function onAbortReq(Tour $tur): bool
    {
        BayLog::debug("%s onAbortReq aborted=%s", $tur, $this->abortable);
        return $this->abortable;
    }

    /////////////////////////////////////
    // Custom methods
    /////////////////////////////////////

    public function sendFileAsync(Tour $tur, string $fname, ?string $charset) : void
    {
        if (is_dir($fname)) {
            throw new HttpException(HttpStatus::FORBIDDEN, $fname);
        }
        elseif (!file_exists($fname)) {
            throw new HttpException(HttpStatus::NOT_FOUND, $fname);
        }

        $mimeType = null;

        $rname = basename($fname);
        $pos = strrpos($rname, '.');
        if ($pos >= 0) {
            $ext = strtolower(substr($rname, $pos + 1));
            $mimeType = Mimes::type($ext);
        }

        if ($mimeType === null)
            $mimeType = "application/octet-stream";

        if (StringUtil::startsWith($mimeType, "text/") && $charset !== null)
            $mimeType = $mimeType . "; charset=" . $charset;

        //resHeaders.setStatus(HttpStatus.OK);
        $tur->res->headers->setContentType($mimeType);
        $tur->res->headers->setContentLength(filesize($fname));
        try {
            $tur->res->sendResHeaders(Tour::TOUR_ID_NOCHECK);

            $bufsize = $tur->ship->protocolHandler->maxResPacketDataSize();
            $agt = GrandAgent::get($tur->ship->agentId);
            $infile = fopen($fname, "rb");

            switch(BayServer::$harbor->fileMultiplexer()) {
                case Harbor::MULTIPLEXER_TYPE_SPIN: {
                    stream_set_blocking($infile, false);
                    $rd = new StreamRudder($infile);
                    $mpx = $agt->spinMultiplexer;
                    break;
                }
                case Harbor::MULTIPLEXER_TYPE_SPIDER: {
                    $timeout = 10;
                    stream_set_blocking($infile, false);
                    $rd = new StreamRudder($infile);
                    $mpx = $agt->spiderMultiplexer;
                    break;
                }

                default:
                    throw new Sink();
            }

            $sendFileShip = new SendFileShip();
            $tp = new PlainTransporter(
                        $mpx,
                        $sendFileShip,
                        true,
                        8192,
                        false);

            $sendFileShip->initSendFile($rd, $tp, $tur);
            $sid = $sendFileShip->shipId;
            $tur->res->setConsumeListener(function ($len, $resume) use ($sendFileShip, $sid) {
                if($resume) {
                    $sendFileShip->resumeRead($sid);
                }
            });

            $mpx->addRudderState($rd, new RudderState($rd, $tp));
            $mpx->reqRead($rd);
        }
        catch (IOException $e) {
            BayLog::error_e(e);
            throw new HttpException(HttpStatus::INTERNAL_SERVER_ERROR, $fname);
        }
    }


}