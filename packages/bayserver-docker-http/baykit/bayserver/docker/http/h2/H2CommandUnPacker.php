<?php

namespace baykit\bayserver\docker\http\h2;


use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\docker\http\h2\command\CmdContinuation;
use baykit\bayserver\docker\http\h2\command\CmdData;
use baykit\bayserver\docker\http\h2\command\CmdGoAway;
use baykit\bayserver\docker\http\h2\command\CmdHeaders;
use baykit\bayserver\docker\http\h2\command\CmdPing;
use baykit\bayserver\docker\http\h2\command\CmdPreface;
use baykit\bayserver\docker\http\h2\command\CmdPriority;
use baykit\bayserver\docker\http\h2\command\CmdRstStream;
use baykit\bayserver\docker\http\h2\command\CmdSettings;
use baykit\bayserver\docker\http\h2\command\CmdWindowUpdate;
use baykit\bayserver\protocol\CommandUnPacker;
use baykit\bayserver\protocol\Packet;
use baykit\bayserver\protocol\ProtocolException;

/**
 * Dispatches incoming H2 frames to command handlers and enforces the
 * frame-level rules of RFC 7540 independent of any particular frame body:
 * header-block continuity (§6.2, §6.10), PUSH_PROMISE from client (§8.2),
 * SETTINGS ACK payload size (§6.5), stream-id presence requirements
 * (§6.x), silent discard of unknown frame types (§4.1), per-stream state
 * machine (§5.1).
 */
class H2CommandUnPacker extends CommandUnPacker {

    // RFC 7540 § 5.1 states tracked by the server. "idle" = absent from
    // $streamStates. Reserved states are skipped because we never
    // exchange PUSH_PROMISE.
    const STATE_OPEN = 1;
    const STATE_HALF_CLOSED_REMOTE = 2;
    const STATE_CLOSED = 3;

    public $cmdHandler;

    /** RFC 7540 §6.2/§6.10: header-block continuity tracking */
    public bool $inHeaderBlock = false;
    public int $headerBlockStreamId = 0;
    /**
     * END_STREAM bit from the HEADERS that started the current block;
     * takes effect only after END_HEADERS finishes the block.
     */
    public bool $pendingEndStream = false;

    /** Per-stream state map: streamId -> STATE_* */
    public array $streamStates = [];
    /** Highest client-initiated (odd) stream id observed so far. */
    public int $highestSeenStreamId = 0;

    public function __construct(H2CommandHandler $handler)
    {
        $this->cmdHandler = $handler;
        $this->reset();
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        $this->inHeaderBlock = false;
        $this->headerBlockStreamId = 0;
        $this->pendingEndStream = false;
        $this->streamStates = [];
        $this->highestSeenStreamId = 0;
    }


    ////////////////////////////////////////////////////////////////////////////////
    // Implements CommandUnPacker
    ////////////////////////////////////////////////////////////////////////////////

    public function packetReceived(Packet $pkt): int
    {
        BayLog::debug("h2: read packet typ=%d strmid=%d len=%d flags=%s",
                        $pkt->type, $pkt->streamId, $pkt->dataLen(), $pkt->flags);

        $type = $pkt->type;

        // RFC 7540 § 4.1: unknown frame types MUST be ignored. Inside a
        // header block they're PROTOCOL_ERROR because CONTINUATION must
        // be the next frame.
        if (!self::isKnownType($type)) {
            if ($this->inHeaderBlock)
                throw new ProtocolException("Unknown frame type $type during header block");
            return NextSocketAction::CONTINUE;
        }

        $this->validateFrame($pkt);
        $this->validateStreamState($pkt);

        switch ($type) {
            case H2Type::PREFACE:
                $cmd = new CmdPreface($pkt->streamId, $pkt->flags);
                break;

            case H2Type::HEADERS:
                $cmd = new CmdHeaders($pkt->streamId, $pkt->flags);
                break;

            case H2Type::PRIORITY:
                $cmd = new CmdPriority($pkt->streamId, $pkt->flags);
                break;

            case H2Type::SETTINGS:
                $cmd = new CmdSettings($pkt->streamId, $pkt->flags);
                break;

            case H2Type::WINDOW_UPDATE:
                $cmd = new CmdWindowUpdate($pkt->streamId, $pkt->flags);
                break;

            case H2Type::DATA:
                $cmd = new CmdData($pkt->streamId, $pkt->flags);
                break;

            case H2Type::GOAWAY:
                $cmd = new CmdGoAway($pkt->streamId, $pkt->flags);
                break;

            case H2Type::PING:
                $cmd = new CmdPing($pkt->streamId, $pkt->flags);
                break;

            case H2Type::RST_STREAM:
                $cmd = new CmdRstStream($pkt->streamId);
                break;

            case H2Type::CONTINUATION:
                $cmd = new CmdContinuation($pkt->streamId, $pkt->flags);
                break;

            default:
                // Unreachable: isKnownType() guards this above.
                throw new ProtocolException("Received packet with unknown type: $type");
        }

        $this->updateHeaderBlockState($pkt);
        $this->updateStreamState($pkt);

        $cmd->unpack($pkt);
        return $cmd->handle($this->cmdHandler);
    }

    private static function isKnownType(int $type) : bool
    {
        switch ($type) {
            case H2Type::PREFACE:
            case H2Type::DATA:
            case H2Type::HEADERS:
            case H2Type::PRIORITY:
            case H2Type::RST_STREAM:
            case H2Type::SETTINGS:
            case H2Type::PUSH_PROMISE:
            case H2Type::PING:
            case H2Type::GOAWAY:
            case H2Type::WINDOW_UPDATE:
            case H2Type::CONTINUATION:
                return true;
            default:
                return false;
        }
    }

    private function validateFrame(Packet $pkt) : void
    {
        $type = $pkt->type;
        $streamId = $pkt->streamId;
        $flags = $pkt->flags;

        // RFC 7540 § 6.10: while a header block is being received, the
        // next frame MUST be a CONTINUATION on the same stream.
        if ($this->inHeaderBlock) {
            if ($type !== H2Type::CONTINUATION)
                throw new ProtocolException("Expected CONTINUATION while in header block: got type=$type");
            if ($streamId !== $this->headerBlockStreamId)
                throw new ProtocolException("CONTINUATION on wrong stream: expected={$this->headerBlockStreamId} got=$streamId");
        } elseif ($type === H2Type::CONTINUATION) {
            // § 6.10: CONTINUATION outside a header block.
            throw new ProtocolException("Unexpected CONTINUATION frame outside header block");
        }

        // RFC 7540 § 6.x: per-frame stream-id rules.
        switch ($type) {
            case H2Type::DATA:
            case H2Type::HEADERS:
            case H2Type::PRIORITY:
            case H2Type::RST_STREAM:
            case H2Type::PUSH_PROMISE:
            case H2Type::CONTINUATION:
                if ($streamId === 0)
                    throw new ProtocolException("Frame type $type requires non-zero stream id");
                break;
            case H2Type::SETTINGS:
            case H2Type::PING:
            case H2Type::GOAWAY:
                if ($streamId !== 0)
                    throw new ProtocolException("Frame type $type requires stream id 0, got $streamId");
                break;
            // WindowUpdate: stream 0 (connection) or specific stream.
            default:
                break;
        }

        // RFC 7540 § 8.2: server MUST NOT receive PUSH_PROMISE.
        if ($type === H2Type::PUSH_PROMISE)
            throw new ProtocolException("Server must not receive PUSH_PROMISE");

        // RFC 7540 § 6.5: SETTINGS with ACK must have an empty payload.
        if ($type === H2Type::SETTINGS && $flags->ack() && $pkt->dataLen() > 0)
            throw new ProtocolException("SETTINGS ACK must have no payload");
    }

    /**
     * RFC 7540 § 5.1: verify the incoming frame is allowed in the
     * current stream state. Connection-level frames (stream id 0)
     * and CONTINUATION are skipped here.
     */
    private function validateStreamState(Packet $pkt) : void
    {
        $type = $pkt->type;
        $streamId = $pkt->streamId;
        if ($streamId === 0 || $type === H2Type::CONTINUATION)
            return;

        $state = $this->streamStates[$streamId] ?? null;

        if ($state === null) {
            // "idle": no state recorded yet. Stream id below the
            // high-water mark means § 5.1.1 implicitly closed it.
            $implicitlyClosed = $streamId <= $this->highestSeenStreamId;
            // RFC 7540 § 5.1.1: client-initiated streams must have odd
            // stream ids, and ids must strictly increase.
            if ($type === H2Type::HEADERS) {
                if (($streamId & 1) === 0)
                    throw new ProtocolException("Client HEADERS with even stream id $streamId");
                if ($implicitlyClosed)
                    throw new ProtocolException("Stream id $streamId is not greater than the previous {$this->highestSeenStreamId}");
                // RFC 7540 § 5.1.2: we advertise MAX_CONCURRENT_STREAMS
                // but do not enforce it here. The receive-side state
                // map cannot see the server's own END_STREAM, so
                // HALF_CLOSED_REMOTE streams linger and counting them
                // against the limit falsely refused well-behaved
                // clients. The concurrency cap is
                // still enforced at InboundShip.getTour via the
                // per-agent TourStore (default 12800 concurrent tours).
            }
            switch ($type) {
                case H2Type::HEADERS:
                case H2Type::PRIORITY:
                    // HEADERS opens a new stream; PRIORITY allowed
                    // in any state including idle.
                    return;
                case H2Type::RST_STREAM:
                case H2Type::DATA:
                case H2Type::WINDOW_UPDATE:
                    if ($implicitlyClosed)
                        throw new ProtocolException("Frame type $type on closed stream $streamId");
                    throw new ProtocolException("Frame type $type on idle stream $streamId");
                default:
                    return;
            }
        }

        switch ($state) {
            case self::STATE_OPEN:
                // Second HEADERS on an open stream is a trailer
                // section; § 8.1 requires END_STREAM.
                if ($type === H2Type::HEADERS && !$pkt->flags->endStream())
                    throw new ProtocolException("Trailer HEADERS on stream $streamId missing END_STREAM");
                return;

            case self::STATE_HALF_CLOSED_REMOTE:
                // Client already sent END_STREAM. DATA/HEADERS from
                // client = STREAM_CLOSED (§ 5.1).
                if ($type === H2Type::DATA || $type === H2Type::HEADERS)
                    throw new ProtocolException("Frame type $type on half-closed (remote) stream $streamId");
                return;

            case self::STATE_CLOSED:
                // Closed streams accept only PRIORITY and WINDOW_UPDATE
                // (the latter may still be in-flight).
                if ($type === H2Type::PRIORITY || $type === H2Type::WINDOW_UPDATE)
                    return;
                throw new ProtocolException("Frame type $type on closed stream $streamId");
        }
    }

    private function countActiveStreams() : int
    {
        $n = 0;
        foreach ($this->streamStates as $s) {
            if ($s !== self::STATE_CLOSED)
                $n++;
        }
        return $n;
    }

    private function updateHeaderBlockState(Packet $pkt) : void
    {
        $type = $pkt->type;
        if ($type === H2Type::HEADERS || $type === H2Type::PUSH_PROMISE) {
            $this->inHeaderBlock = !$pkt->flags->endHeaders();
            $this->headerBlockStreamId = $pkt->streamId;
            $this->pendingEndStream = $pkt->flags->endStream();
        } elseif ($type === H2Type::CONTINUATION) {
            if ($pkt->flags->endHeaders())
                $this->inHeaderBlock = false;
        }
    }

    /**
     * Transition the stream state machine. Called after
     * validateStreamState() has accepted the frame.
     */
    private function updateStreamState(Packet $pkt) : void
    {
        $type = $pkt->type;
        $streamId = $pkt->streamId;
        if ($streamId === 0)
            return;

        $state = $this->streamStates[$streamId] ?? null;

        switch ($type) {
            case H2Type::HEADERS:
                if ($state === null) {
                    // Opening a new stream implicitly closes lower-id
                    // streams that never saw an explicit close (§ 5.1.1).
                    if ($streamId > $this->highestSeenStreamId)
                        $this->highestSeenStreamId = $streamId;
                    $this->streamStates[$streamId] = self::STATE_OPEN;
                    if ($pkt->flags->endHeaders() && $pkt->flags->endStream())
                        $this->streamStates[$streamId] = self::STATE_HALF_CLOSED_REMOTE;
                } elseif ($state === self::STATE_OPEN) {
                    // Trailer section: § 8.1 requires END_STREAM.
                    if ($pkt->flags->endHeaders() && $pkt->flags->endStream())
                        $this->streamStates[$streamId] = self::STATE_HALF_CLOSED_REMOTE;
                }
                break;

            case H2Type::CONTINUATION:
                // HEADERS' END_STREAM takes effect once the header
                // block closes via END_HEADERS on this CONTINUATION.
                if ($pkt->flags->endHeaders() && $this->pendingEndStream && $state === self::STATE_OPEN) {
                    $this->streamStates[$streamId] = self::STATE_HALF_CLOSED_REMOTE;
                }
                if ($pkt->flags->endHeaders())
                    $this->pendingEndStream = false;
                break;

            case H2Type::DATA:
                if ($pkt->flags->endStream() && $state === self::STATE_OPEN)
                    $this->streamStates[$streamId] = self::STATE_HALF_CLOSED_REMOTE;
                break;

            case H2Type::RST_STREAM:
                // Either peer can reset; treat as closed.
                $this->streamStates[$streamId] = self::STATE_CLOSED;
                if ($streamId > $this->highestSeenStreamId)
                    $this->highestSeenStreamId = $streamId;
                break;

            default:
                break;
        }
    }
}
