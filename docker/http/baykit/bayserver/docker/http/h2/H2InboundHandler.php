<?php

namespace baykit\bayserver\docker\http\h2;

use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\docker\base\InboundHandler;
use baykit\bayserver\docker\http\h2\command\CmdData;
use baykit\bayserver\docker\http\h2\command\CmdGoAway;
use baykit\bayserver\docker\http\h2\command\CmdHeaders;
use baykit\bayserver\docker\http\h2\command\CmdPing;
use baykit\bayserver\docker\http\h2\command\CmdPreface;
use baykit\bayserver\docker\http\h2\command\CmdPriority;
use baykit\bayserver\docker\http\h2\command\CmdRstStream;
use baykit\bayserver\docker\http\h2\command\CmdSettings;
use baykit\bayserver\docker\http\h2\command\CmdSettings_Item;
use baykit\bayserver\docker\http\h2\command\CmdWindowUpdate;
use baykit\bayserver\HttpException;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Symbol;
use baykit\bayserver\tour\ReqContentHandler;
use baykit\bayserver\tour\ReqContentHandlerUtil;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\tour\TourStore;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\HttpUtil;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\watercraft\Ship;


class H2InboundHandler extends H2ProtocolHandler implements InboundHandler
{
    private $headerRead;
    private $httpProtocol;

    private $reqContLen;
    private $reqContRead;
    private $windowSize;

    private $settings;
    private $analyzer;

    public function __construct(PacketStore $pktStore)
    {
        parent::__construct($pktStore, true);

        $this->windowSize = BayServer::$harbor->tourBufferSize;

        $this->settings = new H2Settings();
        $this->analyzer = new HeaderBlockAnalyzer();
    }

    /////////////////////////////////////////////////
    // implements Reusable
    /////////////////////////////////////////////////

    public function reset() : void
    {
        parent::reset();
        $this->headerRead = false;

        $this->reqContLen = 0;
        $this->reqContRead = 0;
    }

    /////////////////////////////////////////////////
    // implements InboundHandler
    /////////////////////////////////////////////////

    public function sendResHeaders(Tour $tur): void
    {
        $cmd = new CmdHeaders($tur->req->key);

        $bld = new HeaderBlockBuilder();

        $blk = $bld->buildHeaderBlock(":status", strval($tur->res->headers->status), $this->resHeaderTbl);
        $cmd->headerBlocks[] = $blk;

        // headers
        if(BayServer::$harbor->traceHeader)
            BayLog::info("%s H2 res status: %d", $tur, $tur->res->headers->status);
        foreach ($tur->res->headers->names() as $name) {
            if(StringUtil::eqIgnorecase($name, "connection")) {
                BayLog::trace("%s Connection header is discarded", $tur);
            }
            else {
                $values = $tur->res->headers->values($name);
                //name = name.substring(0, 1).toUpperCase() + name.substring(1);
                foreach ($values as $value) {
                    if (BayServer::$harbor->traceHeader)
                        BayLog::info("%s H2 res header: %s=%s", $tur, $name, $value);
                    $blk = $bld->buildHeaderBlock($name, $value, $this->resHeaderTbl);
                    $cmd->headerBlocks[] = $blk;
                }
            }
        }

        $cmd->flags->setEndHeaders(true);
        $cmd->excluded = false;
        // cmd.streamDependency = streamId;
        $cmd->flags->setPadded(false);

        $this->commandPacker->post($this->ship, $cmd);
    }

    public function sendResContent(Tour $tur, string $bytes, int $ofs, int $len, ?callable $callback): void
    {
        $cmd = new CmdData($tur->req->key, null, $bytes, $ofs, $len);
        $this->commandPacker->post($this->ship, $cmd, $callback);
    }

    public function sendEndTour(Tour $tur, bool $keepAlive, callable $callback): void
    {
        $data = "";
        $cmd = new CmdData($tur->req->key, null, $data, 0, 0);
        $cmd->flags->setEndStream(true);
        $this->commandPacker->post($this->ship, $cmd, $callback);
    }

    public function sendReqProtocolError(ProtocolException $e): bool
    {
        BayLog::error($e, $e->getMessage());
        $cmd = new CmdGoAway(self::CTL_STREAM_ID);
        $cmd->streamId = 0;
        $cmd->lastStreamId = 0;
        $cmd->errorCode = H2ErrorCode::PROTOCOL_ERROR;
        $cmd->debugData = "Thank you!";
        try {
            $this->commandPacker->post($this->ship, $cmd);
            $this->commandPacker->end($this->ship);
        }
        catch(IOException $ex) {
           BayLog::error($ex);
        }
        return false;
    }

    /////////////////////////////////////////////////
    // implements H2CommandHandler
    /////////////////////////////////////////////////

    public function handlePreface(CmdPreface $cmd): int
    {
        BayLog::debug("%s h2: handle_preface: proto=%s", $this->ship, $cmd->protocol);

        $httpProtocol = $cmd->protocol;

        $set = new CmdSettings(self::CTL_STREAM_ID);
        $set->streamId = 0;
        $set->items[] = new CmdSettings_Item(CmdSettings::MAX_CONCURRENT_STREAMS, TourStore::MAX_TOURS);
        $set->items[] = new CmdSettings_Item(CmdSettings::INITIAL_WINDOW_SIZE, $this->windowSize);
        $this->commandPacker->post($this->ship, $set);

        $set = new CmdSettings(self::CTL_STREAM_ID);
        $set->streamId = 0;
        $set->flags->setAck(true);

        return NextSocketAction::CONTINUE;
    }

    public function handleHeaders(CmdHeaders $cmd): int
    {
        BayLog::debug("%s handle_headers: stm=%d dep=%d weight=%d", $this->ship, $cmd->streamId, $cmd->streamDependency, $cmd->weight);
        $tur = $this->getTour($cmd->streamId);
        if($tur == null) {
            BayLog::error(BayMessage::get(Symbol::INT_NO_MORE_TOURS));
            $tur = $this->ship->getTour($cmd->streamId, true);
            $tur->res->sendError(Tour::TOUR_ID_NOCHECK, HttpStatus::SERVICE_UNAVAILABLE, "No available tours");
            //sip.agent.shutdown(false);
            return NextSocketAction::CONTINUE;
        }

        foreach($cmd->headerBlocks as $blk) {
            if($blk->op == HeaderBlock::UPDATE_DYNAMIC_TABLE_SIZE) {
                BayLog::trace("%s header block update table size: %d", $tur, $blk->size);
                $this->reqHeaderTbl->setSize($blk->size);
                continue;
            }
            $this->analyzer->analyzeHeaderBlock($blk, $this->reqHeaderTbl);
            if(BayServer::$harbor->traceHeader)
                BayLog::info("%s req header: %s=%s :%s", $tur, $this->analyzer->name, $this->analyzer->value, $blk);

            if($this->analyzer->name == null) {
                continue;
            }
            else if($this->analyzer->name[0] != ':') {
                $tur->req->headers->add($this->analyzer->name, $this->analyzer->value);
            }
            else if($this->analyzer->method != null) {
                $tur->req->method = $this->analyzer->method;
            }
            else if($this->analyzer->path != null) {
                $tur->req->uri = $this->analyzer->path;
            }
            else if($this->analyzer->scheme != null) {
            }
            else if($this->analyzer->status != null) {
                throw new \Exception();
            }
        }

        if ($cmd->flags->endHeaders()) {
            $tur->req->protocol = "HTTP/2.0";
            BayLog::debug("%s H2 read header method=%s protocol=%s uri=%s contlen=%d",
                $this->ship, $tur->req->method, $tur->req->protocol, $tur->req->uri, $tur->req->headers->contentLength());

            $reqContLen = $tur->req->headers->contentLength();

            if($reqContLen > 0) {
                $sid = $this->ship->shipId;

                $tur->req->setConsumeListener($reqContLen, function ($len, $resume) use ($cmd, $sid) {
                    $this->ship->checkShipId($sid);

                    if ($len > 0) {
                        $upd = new CmdWindowUpdate($cmd->streamId);
                        $upd->windowSizeIncrement = $len;
                        $upd2 = new CmdWindowUpdate(0);
                        $upd2->windowSizeIncrement = $len;
                        $cmdPacker = $this->commandPacker;
                        try {
                            $cmdPacker->post($this->ship, $upd);
                            $cmdPacker->post($this->ship, $upd2);
                        }
                        catch(IOException $e) {
                            BayLog::error_e($e);
                        }
                    }

                    if ($resume)
                        $this->ship->resume(Ship::SHIP_ID_NOCHECK);
                });
            }

            try {
                $this->startTour($tur);
                if ($tur->req->headers->contentLength() <= 0) {
                    $this->endReqContent($tur->id(), $tur);
                }
            } catch (HttpException $e) {
                BayLog::debug("%s Http error occurred: %s", $this, $e);
                if($reqContLen <= 0) {
                    // no post data
                    $tur->res->sendHttpException(Tour::TOUR_ID_NOCHECK, $e);

                    return NextSocketAction::CONTINUE;
                }
                else {
                    // Delay send
                    $tur->error = $e;
                    $tur->req->setContentHandler(ReqContentHandlerUtil::$devNull);
                    return NextSocketAction::CONTINUE;
                }
            }
        }
        return NextSocketAction::CONTINUE;
    }

    public function handleData(CmdData $cmd): int
    {
        BayLog::debug("%s handle_data: stm=%d len=%d", $this->ship, $cmd->streamId, $cmd->length);
        $tur = $this->getTour($cmd->streamId);
        if($tur == null) {
            throw new \InvalidArgumentException("Invalid stream id: " . $cmd->streamId);
        }
        if($tur->req->headers->contentLength() <= 0) {
            throw new ProtocolException("Post content not allowed");
        }

        $success = true;
        if($cmd->length > 0) {
            $success = $tur->req->postContent(Tour::TOUR_ID_NOCHECK, $cmd->data, $cmd->start, $cmd->length);
            if ($tur->req->bytesPosted >= $tur->req->headers->contentLength()) {

                if($tur->error != null){
                    // Error has occurred on header completed

                    $tur->res->sendHttpException(Tour::TOUR_ID_NOCHECK, $tur->error);
                    return NextSocketAction::CONTINUE;
                }
                else {
                    try {
                        $this->endReqContent($tur->id(), $tur);
                    } catch (HttpException $e) {
                        $tur->res->sendHttpException(Tour::TOUR_ID_NOCHECK, $e);
                        return NextSocketAction::CONTINUE;
                    }
                }
            }
        }

        if(!$success)
            return NextSocketAction::SUSPEND;
        else
            return NextSocketAction::CONTINUE;
    }

    public function handlePriority(CmdPriority $cmd): int
    {
        if($cmd->streamId == 0)
            throw new ProtocolException("Invalid streamId");
        return NextSocketAction::CONTINUE;
    }

    public function handleSettings(CmdSettings $cmd): int
    {
        BayLog::debug("%s handleSettings: stmid=%d", $this->ship, $cmd->streamId);
        if($cmd->flags->ack())
            return NextSocketAction::CONTINUE; // ignore ACK

        foreach ($cmd->items as $item) {
            BayLog::debug("%s handle: Setting id=%d, value=%d", $this->ship, $item->id, $item->value);
            switch($item->id) {
                case CmdSettings::HEADER_TABLE_SIZE:
                    $this->settings->headerTableSize = $item->value;
                    break;
                case CmdSettings::ENABLE_PUSH:
                    $this->settings->enablePush = ($item->value != 0);
                    break;
                case CmdSettings::MAX_CONCURRENT_STREAMS:
                    $this->settings->maxConcurrentStreams =$item->value;
                    break;
                case CmdSettings::INITIAL_WINDOW_SIZE:
                    $this->settings->initialWindowSize =$item->value;;
                    break;
                case CmdSettings::MAX_FRAME_SIZE:
                    $this->settings->maxFrameSize =$item->value;
                    break;
                case CmdSettings::MAX_HEADER_LIST_SIZE:
                    $this->settings->maxHeaderListSize =$item->value;
                    break;
                default:
                    BayLog::debug("Invalid settings id (Ignore): %d",$item->id);
            }
        }

        $res = new CmdSettings(0, new H2Flags(H2Flags::FLAGS_ACK));
        $this->commandPacker->post($this->ship, $res);
        return NextSocketAction::CONTINUE;
    }

    public function handleWindowUpdate(CmdWindowUpdate $cmd): int
    {
        if($cmd->windowSizeIncrement == 0)
            throw new ProtocolException("Invalid increment value");
        BayLog::debug("%s handleWindowUpdate: stmid=%d siz=%d", $this->ship,  $cmd->streamId, $cmd->windowSizeIncrement);
        $windowSizse = $cmd->windowSizeIncrement;
        return NextSocketAction::CONTINUE;
    }

    public function handleGoAway(CmdGoAway $cmd): int
    {
        BayLog::debug("%s received GoAway: lastStm=%d code=%d desc=%s debug=%s",
            $this->ship, $cmd->lastStreamId, $cmd->errorCode, H2ErrorCode::$msg->get(strval($cmd->errorCode)), $cmd->debugData);
        return NextSocketAction::CLOSE;
    }

    public function handlePing(CmdPing $cmd): int
    {
        BayLog::debug("%s handle_ping: stm=%d", $this->ship, $cmd->streamId);

        $res = new CmdPing($cmd->streamId, new H2Flags(H2Flags::FLAGS_ACK), $cmd->opaqueData);
        $cmdPacker = $this->commandPacker;
        $cmdPacker->post($this->ship, $res);
        return NextSocketAction::CONTINUE;
    }

    public function handleRstStream(CmdRstStream $cmd): int
    {
        BayLog::debug("%s received RstStream: stmid=%d code=%d desc=%s",
            $this->ship, $cmd->streamId, $cmd->errorCode, H2ErrorCode::$msg->get(strval($cmd->errorCode)));
        return NextSocketAction::CONTINUE;
    }

    /////////////////////////////////////////////////
    // private
    /////////////////////////////////////////////////
    private function getTour(int $key) : ?Tour
    {
        return $this->ship->getTour($key);
    }

    private function endReqContent(int $checkId, Tour $tur) : void
    {
        $tur->req->endContent($checkId);
    }

    private function startTour(Tour $tur) : void
    {
        HttpUtil::parseHostPort($tur, $this->ship->portDocker()->secure() ? 443 : 80);
        HttpUtil::parseAuthrization($tur);

        $tur->req->protocol = $this->httpProtocol;

        $skt = $this->ship->socket;

        // Get remote address
        $clientAdr = $tur->req->headers->get(Headers::X_FORWARDED_FOR);
        if ($clientAdr != null) {
            $tur->req->remoteAddress = $clientAdr;
            $tur->req->remotePort = -1;
        }
        else {
            try {
                $name = stream_socket_get_name($skt, true);
                list($host, $port) = explode(":", $name);
                $tur->req->remoteAddress = $host;
                $tur->req->remotePort = intval($port);
            }
            catch(\Exception $e) {
                BayLog::error_e($e);
                // Unix domain socket
                $tur->req->remoteAddress = null;
                $tur->req->remotePort = -1;
            }
        }
        $tur->req->remoteHostFunc = function () use ($tur) {
            return HttpUtil::resolveHost($tur->req->remoteAddress);
        };

        $name = stream_socket_get_name($skt, false);
        list($host, $port) = explode(":", $name);
        $tur->req->serverAddress = $host;
        $tur->req->serverPort = $tur->req->reqPort;
        $tur->req->serverName = $tur->req->reqHost;
        $tur->isSecure = $this->ship->portDocker()->secure();

        $tur->go();
    }

}