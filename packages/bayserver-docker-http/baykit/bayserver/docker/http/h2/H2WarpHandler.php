<?php
namespace baykit\bayserver\docker\http\h2;

use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\common\WarpData;
use baykit\bayserver\common\WarpHandler;
use baykit\bayserver\common\WarpShip;
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
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Sink;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\StringUtil;

/**
 * H2 reverse-proxy (warp-side) handler.
 *
 * Multiplexes many tours onto one backend TCP connection via H2
 * stream ids: 1, 3, 5,... A WarpShip owns the connection; this
 * handler frames the outbound HEADERS / DATA / END_STREAM and parses
 * the inbound HEADERS / DATA / RST_STREAM / SETTINGS / GOAWAY frames
 * that the backend sends back.
 *
 * Wire-up:
 *  * HtpWarpDocker registers H2WarpProtocolHandlerFactory for
 *    protocol "h2" on the warp side.
 *  * On the first sendReqHeaders call we lazily emit the H2 prelude
 *    (24-byte PRI preface + SETTINGS + connection-level
 *    WINDOW_UPDATE).
 *  * Tours are looked up by the QUIC-like odd stream id assigned in
 *    nextWarpId().
 */
class H2WarpHandler implements WarpHandler, H2Handler
{
    /** Threshold for connection-level WINDOW_UPDATE coalescing. */
    const WINDOW_UPDATE_THRESHOLD = 32768;
    /**
     * Advertised initial per-stream + connection window. Big enough
     * that bench-sized bodies (up to 1MB) never even hit the
     * reactive WINDOW_UPDATE path.
     */
    const INITIAL_WINDOW_SIZE_OUT = 16 * 1024 * 1024;

    public ?H2ProtocolHandler $protocolHandler = null;

    private HeaderBlockAnalyzer $analyzer;
    public HeaderTable $reqHeaderTbl;
    public HeaderTable $resHeaderTbl;

    /** Odd stream id allocator: 1, 3, 5,... */
    private int $curStreamId = 1;
    /**
     * True once the H2 connection prelude (PRI preface + initial
     * SETTINGS + WINDOW_UPDATE) has been pushed onto the wire. Sent
     * lazily on the first sendReqHeaders call so it runs after
     * WarpShip.notifyConnect started draining the queued tours.
     */
    private bool $preludeSent = false;
    /**
     * Pending connection-level WINDOW_UPDATE bytes. Accumulated
     * across DATA frames and flushed when WINDOW_UPDATE_THRESHOLD
     * is reached. The h2 spec only requires that the peer's window
     * not go negative, not that we update on every frame.
     */
    private int $pendingConnWindow = 0;

    public function __construct()
    {
        $this->analyzer = new HeaderBlockAnalyzer();
        $this->reqHeaderTbl = HeaderTable::createDynamicTable();
        $this->resHeaderTbl = HeaderTable::createDynamicTable();
    }

    public function init(H2ProtocolHandler $hnd) : void
    {
        $this->protocolHandler = $hnd;
    }

    public function __toString() : string
    {
        return $this->ship() !== null ? (string)$this->ship() : "H2WarpHandler";
    }

    /////////////////////////////////////
    // Implements Reusable
    /////////////////////////////////////

    public function reset() : void
    {
        $this->curStreamId = 1;
        $this->preludeSent = false;
        $this->pendingConnWindow = 0;
    }

    /////////////////////////////////////
    // implements H2CommandHandler (peer -> warp client)
    /////////////////////////////////////

    public function handlePreface(CmdPreface $cmd) : int
    {
        // Client side never receives a preface.
        throw new \LogicException("H2 warp: unexpected preface from peer");
    }

    public function handleData(CmdData $cmd) : int
    {
        $tur = $this->ship()->getTour($cmd->streamId);
        if ($tur === null) {
            BayLog::warn("%s handleData: no tour for streamId=%d", $this->ship(), $cmd->streamId);
            return NextSocketAction::CONTINUE;
        }

        $available = $tur->res->sendResContent(Tour::TOUR_ID_NOCHECK, $cmd->data, $cmd->start, $cmd->length);

        // Replenish flow-control windows. The h2 spec only requires
        // that the peer's window not go negative, not that we update
        // on every frame. Per-stream: skip on END_STREAM (closed
        // stream + WINDOW_UPDATE = STREAM_CLOSED RST from a strict
        // server such as nginx). Connection-level: coalesce to
        // WINDOW_UPDATE_THRESHOLD.
        if ($cmd->length > 0) {
            if (!$cmd->flags->endStream()) {
                $wd = WarpData::get($tur);
                $wd->pendingStreamWindow += $cmd->length;
                if ($wd->pendingStreamWindow >= self::WINDOW_UPDATE_THRESHOLD) {
                    $upd = new CmdWindowUpdate($cmd->streamId);
                    $upd->windowSizeIncrement = $wd->pendingStreamWindow;
                    $this->ship()->post($upd);
                    $wd->pendingStreamWindow = 0;
                }
            }
            $this->pendingConnWindow += $cmd->length;
            if ($this->pendingConnWindow >= self::WINDOW_UPDATE_THRESHOLD) {
                $upd2 = new CmdWindowUpdate(0);
                $upd2->windowSizeIncrement = $this->pendingConnWindow;
                $this->ship()->post($upd2);
                $this->pendingConnWindow = 0;
            }
        }

        // End-of-stream cleanup must run even when the inbound write
        // buffer is full. Front-end-closed tours never see their
        // inbound write listener fire (no consumer), so a Suspend
        // here would freeze the END_STREAM forever.
        if ($cmd->flags->endStream()) {
            $this->endResContent($tur);
        }

        return $available ? NextSocketAction::CONTINUE : NextSocketAction::SUSPEND;
    }

    public function handleHeaders(CmdHeaders $cmd) : int
    {
        $tur = $this->ship()->getTour($cmd->streamId);
        if ($tur === null) {
            BayLog::error("%s no tour for streamId=%d", $this->ship(), $cmd->streamId);
            return NextSocketAction::CONTINUE;
        }
        if ($tur->res->headerSent)
            throw new ProtocolException("Header command not expected");

        // CmdHeaders.unpack populates $rawHeaderBlock (raw HPACK bytes).
        // We have to decode it into HeaderBlock entries first.
        try {
            $blocks = self::decodeHeaderBlocks($cmd->rawHeaderBlock);
        } catch (\Throwable $e) {
            if ($e instanceof ProtocolException) throw $e;
            throw new ProtocolException("HPACK decode failed: ". $e->getMessage());
        }

        foreach ($blocks as $blk) {
            if ($blk->op === HeaderBlock::UPDATE_DYNAMIC_TABLE_SIZE) {
                $this->resHeaderTbl->setSize($blk->size);
                continue;
            }
            $this->analyzer->analyzeHeaderBlock($blk, $this->resHeaderTbl);
            if ($this->analyzer->name === null)
                continue;

            if ($this->analyzer->name[0] !== ':') {
                $tur->res->headers->add($this->analyzer->name, $this->analyzer->value);
            }
            else if ($this->analyzer->name === HeaderTable::PSEUDO_HEADER_STATUS) {
                $tur->res->headers->status = intval($this->analyzer->value);
            }
            // Other pseudo-headers in a response are RFC violations,
            // but the warp peer is trusted -- ignore them.
        }

        if ($cmd->flags->endHeaders()) {
            $tur->res->sendHeaders(Tour::TOUR_ID_NOCHECK);

            // Wire the back-pressure resume hook (mirrors H1WarpHandler):
            // when the downstream write buffer drains, ask the warp
            // ship to read more from the backend.
            if (!$cmd->flags->endStream()) {
                $wsip = $this->ship();
                $sid = $wsip->shipId;
                $tur->res->setConsumeListener(function ($len, $resume) use ($wsip, $sid) {
                    if ($resume) {
                        $wsip->resumeRead($sid);
                    }
                });
            }

            if ($cmd->flags->endStream()) {
                $this->endResContent($tur);
            }
        }
        return NextSocketAction::CONTINUE;
    }

    public function handlePriority(CmdPriority $cmd) : int
    {
        // PRIORITY frames are deprecated in RFC 9113; no-op.
        return NextSocketAction::CONTINUE;
    }

    public function handleSettings(CmdSettings $cmd) : int
    {
        if (!$cmd->flags->ack()) {
            $res = new CmdSettings(0, new H2Flags(H2Flags::FLAGS_ACK));
            $this->protocolHandler->post($res, true);
        }
        return NextSocketAction::CONTINUE;
    }

    public function handleWindowUpdate(CmdWindowUpdate $cmd) : int
    {
        // Outbound flow-control is not tracked on the warp side
        // we only emit small request bodies that fit in the initial
        // window. Just acknowledge by ignoring.
        return NextSocketAction::CONTINUE;
    }

    public function handleGoAway(CmdGoAway $cmd) : int
    {
        // RFC 9113 §6.8. Common case: errorCode == 0 (NO_ERROR) when
        // the peer (= nginx) hits its per-connection request budget
        // and wants to rotate connections. Strategy: exclude this
        // ship from the multiplex pool so no further tours attach,
        // fail the in-flight tours, and close.
        if ($cmd->errorCode === 0) {
            BayLog::debug("%s received GoAway (NO_ERROR, lastStreamId=%d)",
                $this->ship(), $cmd->lastStreamId);
        } else {
            BayLog::error("%s received GoAway: code=%d desc=%s debug=%s",
                $this->ship(), $cmd->errorCode,
                H2ErrorCode::$msg->get(strval($cmd->errorCode)),
                $cmd->debugData);
        }
        $this->ship()->docker()->excludeFromPool($this->ship());
        $this->ship()->notifyErrorToOwnerTour(HttpStatus::SERVICE_UNAVAILABLE, "Received GoAway packet");
        return NextSocketAction::CLOSE;
    }

    public function handlePing(CmdPing $cmd) : int
    {
        $res = new CmdPing($cmd->streamId, new H2Flags(H2Flags::FLAGS_ACK), $cmd->opaqueData);
        $this->protocolHandler->post($res, true);
        return NextSocketAction::CONTINUE;
    }

    public function handleRstStream(CmdRstStream $cmd) : int
    {
        $tur = $this->ship()->getTour($cmd->streamId);
        if ($cmd->errorCode !== H2ErrorCode::NO_ERROR) {
            BayLog::error("%s received RstStream: code=%d desc=%s",
                $this->ship(), $cmd->errorCode, H2ErrorCode::$msg->get(strval($cmd->errorCode)));
            if ($tur !== null && !$tur->isValid()) {
                $tur->res->sendError(Tour::TOUR_ID_NOCHECK, HttpStatus::SERVICE_UNAVAILABLE, "RST_STREAM");
            }
        }
        return NextSocketAction::CONTINUE;
    }

    public function handleContinuation(CmdContinuation $cmd) : int
    {
        // We do not currently split inbound HEADERS across
        // CONTINUATION frames (backends are expected to send
        // headers in a single HEADERS frame for the body sizes
        // used in this bench). Treat as no-op for now.
        return NextSocketAction::CONTINUE;
    }

    /////////////////////////////////////
    // implements WarpHandler
    /////////////////////////////////////

    public function nextWarpId() : int
    {
        // Client-initiated H2 streams use odd ids: 1, 3, 5,...
        $cur = $this->curStreamId;
        $this->curStreamId += 2;
        return $cur;
    }

    public function maxMultiplexedTours() : int
    {
        // H2 supports stream multiplex on a shared TCP connection.
        // 100 is conservative; tuning hook (HtpWarpDocker parameter)
        // can be added later.
        return 100;
    }

    public function newWarpData(int $warpId) : WarpData
    {
        return new WarpData($this->ship(), $warpId);
    }

    public function sendReqHeaders(Tour $tur) : void
    {
        $this->sendPreludeIfNeeded();
        $this->sendReqHeaderCommand($tur);
    }

    public function sendReqContents(Tour $tur, string $buf, int $start, int $len, ?callable $callback) : void
    {
        $this->sendReqDataCommand($tur, $buf, $start, $len, $callback);
    }

    public function sendEndReq(Tour $tur, bool $keepAlive, ?callable $callback) : void
    {
        // If the request had no body, sendReqHeaders already set
        // END_STREAM on the final HEADERS / CONTINUATION frame.
        // Sending another empty DATA frame with END_STREAM here
        // would land on an already half-closed stream and the
        // backend would respond with RST_STREAM (STREAM_CLOSED).
        // Just notify the consumer listener.
        $reqHadBody =
            $tur->req->headers->contains("content-length")
            || $tur->req->headers->contains("transfer-encoding");
        if (!$reqHadBody) {
            if ($callback !== null) $callback(true);
            return;
        }
        $streamId = WarpData::get($tur)->warpId;
        $cmd = new CmdData($streamId, null, "", 0, 0);
        $cmd->flags->setEndStream(true);
        $this->ship()->post($cmd, $callback);
    }

    public function verifyProtocol(string $protocol) : void
    {
        // H2WarpDocker forces H2 from the start (preface goes out
        // before any backend response); no ALPN switch to verify.
    }

    public function onProtocolError(ProtocolException $e) : bool
    {
        throw new Sink("H2 warp onProtocolError: ". $e->getMessage());
    }

    /////////////////////////////////////
    // Custom methods
    /////////////////////////////////////

    private function ship() : WarpShip
    {
        return $this->protocolHandler->ship;
    }

    /**
     * Send the H2 connection prelude (RFC 7540 § 3.5) on the first
     * tour. Called lazily so it runs after the TCP connect completes
     * (= after WarpShip.notifyConnect started draining queued tours).
     */
    private function sendPreludeIfNeeded() : void
    {
        if ($this->preludeSent) return;
        $sip = $this->ship();

        // 1. Connection preface -- empty CmdPreface. Its pack()
        // emits the 24 preface bytes raw (not in H2 frame format).
        $preface = new CmdPreface(0, new H2Flags());
        $sip->post($preface);

        // 2. Initial SETTINGS frame on stream 0, no ACK.
        $set = new CmdSettings(H2ProtocolHandler::CTL_STREAM_ID);
        $set->streamId = 0;
        $set->items[] = new CmdSettings_Item(CmdSettings::MAX_CONCURRENT_STREAMS,
            max(BayServer::$harbor->maxToursPerShip(), 100));
        $set->items[] = new CmdSettings_Item(CmdSettings::INITIAL_WINDOW_SIZE,
            self::INITIAL_WINDOW_SIZE_OUT);
        $sip->post($set);

        // 3. Connection-level WINDOW_UPDATE on stream 0.
        $connUp = new CmdWindowUpdate(0);
        $connUp->windowSizeIncrement = self::INITIAL_WINDOW_SIZE_OUT;
        $sip->post($connUp);

        $this->preludeSent = true;
    }

    private function sendReqHeaderCommand(Tour $tur) : void
    {
        $town = $tur->town;
        $townPath = $town->name;
        if (!StringUtil::endsWith($townPath, "/"))
            $townPath.= "/";
        $sip = $this->ship();
        $newUri = $sip->docker()->warpBase(). substr($tur->req->uri, strlen($townPath));

        $bld = new HeaderBlockBuilder();
        $blocks = [];

        $blocks[] = $bld->buildHeaderBlock(HeaderTable::PSEUDO_HEADER_METHOD, $tur->req->method, $this->reqHeaderTbl);
        $blocks[] = $bld->buildHeaderBlock(HeaderTable::PSEUDO_HEADER_PATH, $newUri, $this->reqHeaderTbl);
        $blocks[] = $bld->buildHeaderBlock(HeaderTable::PSEUDO_HEADER_SCHEME,
            $tur->isSecure ? "https" : "http", $this->reqHeaderTbl);
        $blocks[] = $bld->buildHeaderBlock(HeaderTable::PSEUDO_HEADER_AUTHORITY,
            $sip->docker()->host(). ":". $sip->docker()->port(), $this->reqHeaderTbl);

        // Regular request headers: lowercase, no connection-specific
        // fields (RFC 7540 § 8.1.2.2). The Host header is covered by
        // :authority and must not appear here.
        foreach ($tur->req->headers->names() as $name) {
            $lower = strtolower($name);
            if ($lower === "connection" || $lower === "host"
                    || $lower === "keep-alive" || $lower === "transfer-encoding"
                    || $lower === "upgrade" || $lower === "proxy-connection") {
                continue;
            }
            foreach ($tur->req->headers->values($name) as $value) {
                $blocks[] = $bld->buildHeaderBlock($lower, $value, $this->reqHeaderTbl);
            }
        }

        $streamId = WarpData::get($tur)->warpId;
        $endStream = !$tur->req->headers->contains("content-length")
            && !$tur->req->headers->contains("transfer-encoding");

        // Single HEADERS frame -- our header counts (~6 entries) are
        // far below DEFAULT_PAYLOAD_MAXLEN (16384) even with HPACK
        // expansion. CONTINUATION splitting for huge header blocks
        // is not implemented (bench traffic doesn't trigger it).
        $hcmd = new CmdHeaders($streamId);
        $hcmd->excluded = false;
        $hcmd->headerBlocks = $blocks;
        $hcmd->flags->setEndHeaders(true);
        if ($endStream) $hcmd->flags->setEndStream(true);
        $hcmd->flags->setPadded(false);
        $sip->post($hcmd);
    }

    private function sendReqDataCommand(Tour $tur, string $buf, int $start, int $len, ?callable $callback) : void
    {
        $streamId = WarpData::get($tur)->warpId;
        $cmd = new CmdData($streamId, null, $buf, $start, $len);
        $this->ship()->post($cmd, $callback);
    }

    private function endResContent(Tour $tur) : void
    {
        // Order matters: endWarpTour reads WarpData.get($tur); after
        // tur.res.endResContent the tour resets and clears the
        // contentHandler. Mirrors H1WarpHandler.endResContent.
        $this->ship()->endWarpTour($tur, true);
        $tur->res->endResContent(Tour::TOUR_ID_NOCHECK);
    }

    /**
     * Decode an HPACK header block fragment string into HeaderBlock
     * entries via a synthetic H2Packet. Mirrors the H2InboundHandler
     * onEndHeader path.
     */
    private static function decodeHeaderBlocks(string $bytes) : array
    {
        $pkt = new H2Packet(H2Type::HEADERS);
        $pkt->headerLen = 0;
        $pkt->buf = $bytes;
        $pkt->bufLen = strlen($bytes);
        $acc = $pkt->newH2DataAccessor();

        $blocks = [];
        $end = strlen($bytes);
        while ($acc->pos < $end) {
            $blocks[] = HeaderBlock::unpack($acc);
        }
        return $blocks;
    }
}
