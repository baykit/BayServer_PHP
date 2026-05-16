<?php

namespace baykit\bayserver\docker\http\h2;

use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\common\InboundHandler;
use baykit\bayserver\common\InboundShip;
use baykit\bayserver\docker\http\h2\command\CmdContinuation;
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
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\ship\Ship;
use baykit\bayserver\Symbol;
use baykit\bayserver\tour\ReqContentHandlerUtil;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\tour\TourStore;
use baykit\bayserver\util\ClassUtil;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\HttpUtil;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\StringUtil;


class H2InboundHandler implements H2Handler, InboundHandler
{
    private H2ProtocolHandler $protocolHandler;
    private bool $headerRead = false;
    private ?string $httpProtocol = null;

    private int $reqContLen = 0;
    private int $reqContRead = 0;
    private int $windowSize;

    // RFC 7540 § 6.9.1: flow-control window tracking. The peer-driven
    // send window must never exceed 2^31-1.
    const MAX_WINDOW = 0x7fffffff;
    const DEFAULT_INITIAL_WINDOW = 65535;
    private int $connSendWindow = self::DEFAULT_INITIAL_WINDOW;
    private array $streamSendWindows = [];

    // Pseudo-header / header-field validation state, tracked across the
    // whole header block (HEADERS [+ CONTINUATION...]) so duplicates
    // and ordering can be detected. Reset on each new HEADERS frame.
    // Mirrors
    private bool $sawMethod = false;
    private bool $sawScheme = false;
    private bool $sawPath = false;
    private bool $sawAuthority = false;
    private bool $sawRegularHeader = false;

    private H2Settings $settings;
    private HeaderBlockAnalyzer $analyzer;

    public HeaderTable $reqHeaderTbl;
    public HeaderTable $resHeaderTbl;

    /**
     * Raw header-block bytes accumulated across HEADERS + zero or more
     * CONTINUATION frames. HPACK is only valid against the concatenation,
     * not against individual frame payloads (RFC 7540 § 4.3). Mirrors
     * upstream's H2InboundHandler.headerBuffer.
     */
    private string $headerBuffer = "";

    public function __construct()
    {
        $this->windowSize = BayServer::$harbor->shipBufferSize();
        $this->settings = new H2Settings();
        $this->analyzer = new HeaderBlockAnalyzer();
        $this->reqHeaderTbl = HeaderTable::createDynamicTable();
        $this->resHeaderTbl = HeaderTable::createDynamicTable();

    }

    public function init(H2ProtocolHandler $hnd): void
    {
        $this->protocolHandler = $hnd;
    }

    public function __toString(): string
    {
        return ClassUtil::localName(get_class($this));
    }

    /////////////////////////////////////////////////
    // implements Reusable
    /////////////////////////////////////////////////

    public function reset() : void
    {
        $this->headerRead = false;
        $this->reqContLen = 0;
        $this->reqContRead = 0;
        $this->headerBuffer = "";

        // Flow-control tracking is per-connection; pooled handlers
        // must start each new connection with fresh windows
        //. Without this, connSendWindow drifts well
        // below zero across many requests and then trips the
        // overflow checks on later connections.
        $this->connSendWindow = self::DEFAULT_INITIAL_WINDOW;
        $this->streamSendWindows = [];
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
        if(BayServer::$harbor->traceHeader())
            BayLog::info("%s H2 res status: %d", $tur, $tur->res->headers->status);
        foreach ($tur->res->headers->names() as $name) {
            if(StringUtil::eqIgnorecase($name, "connection")) {
                BayLog::trace("%s Connection header is discarded", $tur);
            }
            else {
                $values = $tur->res->headers->values($name);
                //name = name.substring(0, 1).toUpperCase() + name.substring(1);
                foreach ($values as $value) {
                    if (BayServer::$harbor->traceHeader())
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

        $this->protocolHandler->post($cmd, false);
    }

    public function sendResContent(Tour $tur, string $bytes, int $ofs, int $len, ?callable $callback): bool
    {
        // Account for the bytes we're about to send against the
        // flow-control windows. Without this, handleWindowUpdate
        // only ever sees WINDOW_UPDATE additions and eventually trips
        // the > 2^31-1 guard on long-lived high-throughput
        // connections, killing the agent.
        if ($len > 0) {
            $streamId = $tur->req->key;
            $this->connSendWindow -= $len;
            $strWin = ($this->streamSendWindows[$streamId] ?? self::DEFAULT_INITIAL_WINDOW) - $len;
            $this->streamSendWindows[$streamId] = $strWin;
        }
        $cmd = new CmdData($tur->req->key, null, $bytes, $ofs, $len);
        return $this->protocolHandler->post($cmd, false, $callback);
    }

    public function transferContent(Tour $tur, \baykit\bayserver\rudder\Rudder $fileRd, int $ofs, int $len, ?callable $callback): void
    {
        // HTTP/2 frames are encrypted at the TLS layer, so sendfile cannot
        // bypass user space.
        throw new Sink();
    }

    public function sendEndTour(Tour $tur, ?callable $callback): void
    {
        $data = "";
        $cmd = new CmdData($tur->req->key, null, $data, 0, 0);
        $cmd->flags->setEndStream(true);
        $this->protocolHandler->post($cmd, true, $callback);
    }

    function onProtocolError(ProtocolException $e): bool
    {
        BayLog::debug_e($e, "%s Protocol error: %s", $this, $e->getMessage());
        $cmd = new CmdGoAway(H2ProtocolHandler::CTL_STREAM_ID);
        $cmd->streamId = 0;
        $cmd->lastStreamId = 0;
        // H2ProtocolException carries a caller-specified error code
        // (FLOW_CONTROL_ERROR, COMPRESSION_ERROR, etc.); bare
        // ProtocolException defaults to PROTOCOL_ERROR per RFC 7540
        // § 5.4.
        $cmd->errorCode = ($e instanceof H2ProtocolException)
            ? $e->errorCode
            : H2ErrorCode::PROTOCOL_ERROR;
        $cmd->debugData = "Thank you!";
        try {
            // Defer the close until the GOAWAY has actually been
            // written; synchronously calling postClose() often closes
            // the socket before the frame reaches the peer (h2spec
            // sees "unexpected EOF" instead of the GOAWAY). Mirrors
            //
            $sip = $this->protocolHandler->ship;
            $this->protocolHandler->post($cmd, true, function ($avail) use ($sip) {
                $sip->postClose();
            });
        }
        catch(IOException $ex) {
            BayLog::error_e($ex);
        }
        return false;
    }

    /////////////////////////////////////////////////
    // implements H2CommandHandler
    /////////////////////////////////////////////////

    public function handlePreface(CmdPreface $cmd): int
    {
        $sip = $this->ship();
        BayLog::debug("%s h2: handle_preface: proto=%s", $sip, $cmd->protocol);

        $httpProtocol = $cmd->protocol;

        $set = new CmdSettings(H2ProtocolHandler::CTL_STREAM_ID);
        $set->streamId = 0;
        // Use the harbor-configured per-ship tour limit so a single TLS
        // connection can't open MAX_TOURS streams in parallel. Mirrors
        //
        $set->items[] = new CmdSettings_Item(CmdSettings::MAX_CONCURRENT_STREAMS, BayServer::$harbor->maxToursPerShip());
        $set->items[] = new CmdSettings_Item(CmdSettings::INITIAL_WINDOW_SIZE, $this->windowSize);
        $this->protocolHandler->post($set, true);

        $set = new CmdSettings(H2ProtocolHandler::CTL_STREAM_ID);
        $set->streamId = 0;
        $set->flags->setAck(true);

        return NextSocketAction::CONTINUE;
    }

    public function handleHeaders(CmdHeaders $cmd): int
    {
        $sip = $this->ship();
        BayLog::debug("%s handle_headers: stm=%d dep=%d weight=%d", $sip, $cmd->streamId, $cmd->streamDependency, $cmd->weight);
        $tur = $this->getTour($cmd->streamId);
        if($tur == null) {
            // reverted REFUSED_STREAM here: the receive-
            // side state map can't see the server's own END_STREAM,
            // so a strict MAX_CONCURRENT_STREAMS check falsely
            // refused well-behaved clients. Fall back to sending a
            // 503 instead -- the concurrency cap is still enforced
            // at the per-agent TourStore.
            BayLog::error(BayMessage::get(Symbol::INT_NO_MORE_TOURS));
            $tur = $sip->getTour($cmd->streamId, true);
            $tur->res->sendError(Tour::TOUR_ID_NOCHECK, HttpStatus::SERVICE_UNAVAILABLE, "No available tours");
            return NextSocketAction::CONTINUE;
        }

        // New HEADERS frame: reset pseudo-header validation state.
        // CONTINUATION carries over the same state.
        $this->sawMethod = false;
        $this->sawScheme = false;
        $this->sawPath = false;
        $this->sawAuthority = false;
        $this->sawRegularHeader = false;

        $this->headerBuffer = $cmd->rawHeaderBlock;

        if ($cmd->flags->endHeaders()) {
            return $this->onEndHeader($tur);
        }
        return NextSocketAction::CONTINUE;
    }

    public function handleData(CmdData $cmd): int
    {
        BayLog::debug("%s handle_data: stm=%d len=%d", $this->ship(), $cmd->streamId, $cmd->length);
        $tur = $this->getTour($cmd->streamId);
        if($tur == null) {
            throw new \InvalidArgumentException("Invalid stream id: ". $cmd->streamId);
        }

        try {
            $success = true;
            if($cmd->length > 0) {
                $tid = $tur->tourId;
                $success = $tur->req->postReqContent(Tour::TOUR_ID_NOCHECK,
                                            $cmd->data,
                                            $cmd->start,
                                            $cmd->length,
                                            function ($len, $resume) use ($tur, $tid, $cmd) {
                                                $tur->checkTourId($tid);

                                                if ($len > 0) {
                                                    $upd = new CmdWindowUpdate($cmd->streamId);
                                                    $upd->windowSizeIncrement = $len;
                                                    $upd2 = new CmdWindowUpdate(0);
                                                    $upd2->windowSizeIncrement = $len;
                                                    try {
                                                        // WINDOW_UPDATE pair: emit the connection-level
                                                        // update with flush=false so it batches with the
                                                        // stream-level one.
                                                        $this->protocolHandler->post($upd, false);
                                                        $this->protocolHandler->post($upd2, true);
                                                    }
                                                    catch(IOException $e) {
                                                        BayLog::error_e($e);
                                                    }
                                                }

                                                if ($resume)
                                                    $tur->ship->resumeRead(Ship::SHIP_ID_NOCHECK);

                                            });
                if ($tur->req->bytesPosted >= $tur->req->headers->contentLength()) {

                    if($tur->error != null){
                        // Error has occurred on header completed
                        BayLog::debug("%s Delay send error", $tur);
                        throw $tur->error;
                    }
                    else {
                        $this->endReqContent($tur->id(), $tur);
                    }
                }
            }
        }
        catch (HttpException $e) {
            $tur->req->abort();
            $tur->res->sendHttpException(Tour::TOUR_ID_NOCHECK, $e);
            return NextSocketAction::CONTINUE;
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
        BayLog::debug("%s handleSettings: stmid=%d", $this->ship(), $cmd->streamId);
        if($cmd->flags->ack())
            return NextSocketAction::CONTINUE; // ignore ACK

        foreach ($cmd->items as $item) {
            BayLog::debug("%s handle: Setting id=%d, value=%d", $this->ship(), $item->id, $item->value);
            switch($item->id) {
                case CmdSettings::HEADER_TABLE_SIZE:
                    $this->settings->headerTableSize = $item->value;
                    break;
                case CmdSettings::ENABLE_PUSH:
                    // RFC 7540 § 6.5.2: ENABLE_PUSH must be 0 or 1.
                    if ($item->value !== 0 && $item->value !== 1)
                        throw new ProtocolException("SETTINGS_ENABLE_PUSH must be 0 or 1, got {$item->value}");
                    $this->settings->enablePush = ($item->value != 0);
                    break;
                case CmdSettings::MAX_CONCURRENT_STREAMS:
                    $this->settings->maxConcurrentStreams =$item->value;
                    break;
                case CmdSettings::INITIAL_WINDOW_SIZE:
                    // RFC 7540 § 6.5.2: INITIAL_WINDOW_SIZE must not
                    // exceed 2^31-1. PHP's PacketPartAccessor::getInt()
                    // uses unpack("N") and returns the value unsigned
                    // in a 64-bit PHP int, so overflow shows up as a
                    // value above 0x7fffffff rather than as a negative
                    // signed int.
                    if ($item->value < 0 || $item->value > 0x7fffffff)
                        throw new H2ProtocolException(H2ErrorCode::FLOW_CONTROL_ERROR, "SETTINGS_INITIAL_WINDOW_SIZE exceeds 2^31-1: {$item->value}");
                    $this->settings->initialWindowSize =$item->value;
                    break;
                case CmdSettings::MAX_FRAME_SIZE:
                    // RFC 7540 § 6.5.2: MAX_FRAME_SIZE must be in
                    // [2^14, 2^24-1] (16384..16777215).
                    if ($item->value < H2Packet::DEFAULT_PAYLOAD_MAXLEN
                            || $item->value > H2Packet::MAX_PAYLOAD_MAXLEN)
                        throw new ProtocolException("SETTINGS_MAX_FRAME_SIZE out of range: {$item->value}");
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
        $this->protocolHandler->post($res, true);
        return NextSocketAction::CONTINUE;
    }

    public function handleWindowUpdate(CmdWindowUpdate $cmd): int
    {
        // RFC 7540 § 6.9: WINDOW_UPDATE with increment 0 is PROTOCOL_ERROR
        // (or FLOW_CONTROL_ERROR at the stream level -- both close the peer).
        if($cmd->windowSizeIncrement == 0)
            throw new ProtocolException("Invalid increment value");
        BayLog::debug("%s handleWindowUpdate: stmid=%d siz=%d", $this->ship(),  $cmd->streamId, $cmd->windowSizeIncrement);

        // RFC 7540 § 6.9.1: window must not exceed 2^31-1. Connection-
        // level overflow -> GOAWAY(FLOW_CONTROL_ERROR); stream-level
        // overflow -> RST_STREAM(FLOW_CONTROL_ERROR).
        if ($cmd->streamId === 0) {
            $this->connSendWindow += $cmd->windowSizeIncrement;
            if ($this->connSendWindow > self::MAX_WINDOW)
                throw new H2ProtocolException(H2ErrorCode::FLOW_CONTROL_ERROR, "Connection send window overflow: {$this->connSendWindow}");
        } else {
            $win = ($this->streamSendWindows[$cmd->streamId] ?? self::DEFAULT_INITIAL_WINDOW)
                + $cmd->windowSizeIncrement;
            if ($win > self::MAX_WINDOW) {
                $rst = new CmdRstStream($cmd->streamId);
                $rst->errorCode = H2ErrorCode::FLOW_CONTROL_ERROR;
                $this->protocolHandler->post($rst, true);
                unset($this->streamSendWindows[$cmd->streamId]);
                return NextSocketAction::CONTINUE;
            }
            $this->streamSendWindows[$cmd->streamId] = $win;
        }
        return NextSocketAction::CONTINUE;
    }

    public function handleGoAway(CmdGoAway $cmd): int
    {
        BayLog::debug("%s received GoAway: lastStm=%d code=%d desc=%s debug=%s",
            $this->ship(), $cmd->lastStreamId, $cmd->errorCode, H2ErrorCode::$msg->get(strval($cmd->errorCode)), $cmd->debugData);
        return NextSocketAction::CLOSE;
    }

    public function handlePing(CmdPing $cmd): int
    {
        $sip = $this->ship();
        BayLog::debug("%s handle_ping: stm=%d ack=%b", $sip, $cmd->streamId, $cmd->flags->ack());

        // RFC 7540 § 6.7: a PING with ACK is a response to a PING the
        // endpoint sent; we never send PINGs, and an endpoint MUST NOT
        // respond to PING with ACK set.
        if ($cmd->flags->ack())
            return NextSocketAction::CONTINUE;

        $res = new CmdPing($cmd->streamId, new H2Flags(H2Flags::FLAGS_ACK), $cmd->opaqueData);
        $this->protocolHandler->post($res, true);
        return NextSocketAction::CONTINUE;
    }

    public function handleRstStream(CmdRstStream $cmd): int
    {
        BayLog::debug("%s received RstStream: stmid=%d code=%d desc=%s",
            $this->ship(), $cmd->streamId, $cmd->errorCode, H2ErrorCode::$msg->get(strval($cmd->errorCode)));
        return NextSocketAction::CONTINUE;
    }

    public function handleContinuation(CmdContinuation $cmd): int
    {
        $sip = $this->ship();
        BayLog::debug("%s handle_continuation: stm=%d len=%d", $sip, $cmd->streamId, strlen($cmd->rawHeaderBlock));
        $tur = $this->getTour($cmd->streamId);
        if($tur == null) {
            // CONTINUATION arrived without a prior HEADERS for this stream:
            // protocol error per RFC 7540 sec 6.10.
            throw new ProtocolException("H2 CONTINUATION for unknown stream id: ". $cmd->streamId);
        }

        $this->headerBuffer.= $cmd->rawHeaderBlock;

        if ($cmd->flags->endHeaders()) {
            return $this->onEndHeader($tur);
        }
        return NextSocketAction::CONTINUE;
    }

    /////////////////////////////////////////////////
    // private
    /////////////////////////////////////////////////

    /**
     * Called once END_HEADERS is observed (either on the HEADERS frame
     * itself or on a terminating CONTINUATION). Decodes the accumulated
     * raw header bytes -- HPACK is only valid against the full
     * concatenation, never against an individual frame's fragment
     * applies them to the request, then starts the tour. onEndHeader.
     */
    private function onEndHeader(Tour $tur) : int
    {
        try {
            $blocks = $this->decodeHeaderBlocks($this->headerBuffer);
            $this->applyHeaderBlocks($tur, $blocks);
        }
        catch (\Throwable $e) {
            if ($e instanceof ProtocolException) {
                $this->headerBuffer = "";
                throw $e;
            }
            // Truncated / corrupt HPACK -> RFC 7541 § 2.3.3
            // COMPRESSION_ERROR instead of an agent-killing trace.
            // Mirrors
            $this->headerBuffer = "";
            throw new ProtocolException("HPACK decode failed: ". $e->getMessage());
        }
        $this->headerBuffer = "";
        return $this->finishHeaderBlock($tur);
    }

    /**
     * Decode a complete header block fragment string into HeaderBlock
     * entries. The fragment must be the concatenation of all HEADERS +
     * CONTINUATION payloads (no partial HPACK encoding).
     */
    private function decodeHeaderBlocks(string $bytes) : array
    {
        $pkt = new H2Packet(H2Type::HEADERS);
        $pkt->headerLen = 0;
        $pkt->buf = $bytes;
        $pkt->bufLen = strlen($bytes);
        $acc = $pkt->newH2DataAccessor();

        // RFC 7541 § 4.2: dynamic-table size updates must appear at the
        // very start of a header block. Mirrors CmdHeaders::readHeaderBlock
        // pre-refactor.
        $seenNonSizeUpdate = false;
        $blocks = [];
        $end = strlen($bytes);
        while ($acc->pos < $end) {
            $blk = HeaderBlock::unpack($acc);
            if ($blk->op === HeaderBlock::UPDATE_DYNAMIC_TABLE_SIZE) {
                if ($seenNonSizeUpdate)
                    throw new ProtocolException("Dynamic table size update must appear at the start of a header block");
            }
            else {
                $seenNonSizeUpdate = true;
            }
            $blocks[] = $blk;
        }
        return $blocks;
    }

    /**
     * Decode and apply a list of HeaderBlock entries to the request.
     * Used by both handleHeaders and handleContinuation so CONTINUATION
     * frames can extend the header set of the previously-started tour.
     */
    private function applyHeaderBlocks(Tour $tur, array $blocks) : void
    {
        foreach($blocks as $blk) {
            if($blk->op == HeaderBlock::UPDATE_DYNAMIC_TABLE_SIZE) {
                BayLog::trace("%s header block update table size: %d", $tur, $blk->size);
                $this->reqHeaderTbl->setSize($blk->size);
                continue;
            }
            $this->analyzer->analyzeHeaderBlock($blk, $this->reqHeaderTbl);
            if(BayServer::$harbor->traceHeader())
                BayLog::info("%s req header: %s=%s :%s", $tur, $this->analyzer->name, $this->analyzer->value, $blk);

            if($this->analyzer->name == null) {
                continue;
            }

            // RFC 7540 § 8.1.2: header field names must be lowercase.
            // Mirrors
            if (self::hasUpperCase($this->analyzer->rawName))
                throw new ProtocolException("Header name must be lowercase: {$this->analyzer->rawName}");

            if ($this->analyzer->pseudo) {
                // § 8.1.2.1: pseudo-headers must precede regular headers.
                if ($this->sawRegularHeader)
                    throw new ProtocolException("Pseudo-header {$this->analyzer->rawName} appears after a regular header");

                switch ($this->analyzer->rawName) {
                    case HeaderTable::PSEUDO_HEADER_METHOD:
                        if ($this->sawMethod)
                            throw new ProtocolException("Duplicated :method");
                        $this->sawMethod = true;
                        $tur->req->method = $this->analyzer->method;
                        break;
                    case HeaderTable::PSEUDO_HEADER_SCHEME:
                        if ($this->sawScheme)
                            throw new ProtocolException("Duplicated :scheme");
                        $this->sawScheme = true;
                        break;
                    case HeaderTable::PSEUDO_HEADER_PATH:
                        if ($this->sawPath)
                            throw new ProtocolException("Duplicated :path");
                        // § 8.1.2.3: :path must not be empty for http/https.
                        if ($this->analyzer->path === null || $this->analyzer->path === "")
                            throw new ProtocolException("Empty :path pseudo-header");
                        $this->sawPath = true;
                        $tur->req->uri = $this->analyzer->path;
                        break;
                    case HeaderTable::PSEUDO_HEADER_AUTHORITY:
                        if ($this->sawAuthority)
                            throw new ProtocolException("Duplicated :authority");
                        $this->sawAuthority = true;
                        $tur->req->headers->add($this->analyzer->name, $this->analyzer->value);
                        break;
                    case HeaderTable::PSEUDO_HEADER_STATUS:
                        throw new ProtocolException(":status pseudo-header is invalid in a request");
                    default:
                        throw new ProtocolException("Unknown pseudo-header: {$this->analyzer->rawName}");
                }
            }
            else {
                $this->sawRegularHeader = true;
                // § 8.1.2.2: connection-specific header fields are
                // forbidden in HTTP/2. Only "trailers" is allowed
                // as a TE value.
                $lname = strtolower($this->analyzer->name);
                if ($lname === "connection" || $lname === "keep-alive"
                        || $lname === "proxy-connection"
                        || $lname === "transfer-encoding"
                        || $lname === "upgrade")
                    throw new ProtocolException("Connection-specific header in HTTP/2: {$this->analyzer->name}");
                if ($lname === "te" && strtolower($this->analyzer->value) !== "trailers")
                    throw new ProtocolException("TE header with value other than 'trailers': {$this->analyzer->value}");
                $tur->req->headers->add($this->analyzer->name, $this->analyzer->value);
            }
        }
    }

    private static function hasUpperCase(?string $s) : bool
    {
        if ($s === null)
            return false;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($s[$i]);
            if ($c >= 65 && $c <= 90)  // 'A'..'Z'
                return true;
        }
        return false;
    }

    /**
     * Called when END_HEADERS flag is seen, after the full header block
     * (HEADERS [+ CONTINUATION...]) has been accumulated. Starts the tour.
     */
    private function finishHeaderBlock(Tour $tur) : int
    {
        // § 8.1.2.3: request MUST include :method, :scheme, :path.
        // Mirrors
        if (!$this->sawMethod)
            throw new ProtocolException("Missing :method pseudo-header");
        if (!$this->sawScheme)
            throw new ProtocolException("Missing :scheme pseudo-header");
        if (!$this->sawPath)
            throw new ProtocolException("Missing :path pseudo-header");

        $sip = $this->ship();
        $tur->req->protocol = "HTTP/2.0";
        BayLog::debug("%s H2 read header method=%s protocol=%s uri=%s contlen=%d",
            $sip, $tur->req->method, $tur->req->protocol, $tur->req->uri, $tur->req->headers->contentLength());

        HttpUtil::checkUri($tur->req->uri);
        $reqContLen = $tur->req->headers->contentLength();

        if($reqContLen > 0) {
            $tur->req->setLimit($reqContLen);
        }

        try {
            if($tur->req->uri == null) {
                throw new HttpException(HttpStatus::BAD_REQUEST, "Missing uri");
            }

            $this->startTour($tur);
            if ($tur->req->headers->contentLength() <= 0) {
                $this->endReqContent($tur->id(), $tur);
            }
        } catch (HttpException $e) {
            BayLog::debug_e($e, "%s Http error occurred", $this);
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
        return NextSocketAction::CONTINUE;
    }

    private function getTour(int $key) : ?Tour
    {
        return $this->ship()->getTour($key);
    }

    private function endReqContent(int $checkId, Tour $tur) : void
    {
        $tur->req->endReqContent($checkId);
    }

    private function startTour(Tour $tur) : void
    {
        $sip = $this->ship();
        HttpUtil::parseHostPort($tur, $this->ship()->portDocker()->secure() ? 443 : 80);
        HttpUtil::parseAuthrization($tur);

        $tur->req->protocol = $this->httpProtocol;

        $skt = $sip->rudder->key();

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
        $tur->isSecure = $sip->portDocker()->secure();

        $tur->go();
    }

    private function ship(): InboundShip
    {
        return $this->protocolHandler->ship;
    }
}