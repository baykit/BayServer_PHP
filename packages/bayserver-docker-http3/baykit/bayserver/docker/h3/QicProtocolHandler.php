<?php
namespace baykit\bayserver\docker\h3;

use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\common\Multiplexer;
use baykit\bayserver\docker\h3\command\CmdData;
use baykit\bayserver\docker\h3\command\CmdFinished;
use baykit\bayserver\docker\h3\command\CmdHeader;
use baykit\bayserver\protocol\CommandPacker;
use baykit\bayserver\protocol\CommandUnPacker;
use baykit\bayserver\protocol\Packet;
use baykit\bayserver\protocol\PacketPacker;
use baykit\bayserver\protocol\PacketUnPacker;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\Sink;
use baykit\bayserver\util\IOException;
use Croute\Binding;
use Croute\CrouteError;
use Croute\H3\Config as H3Config;
use Croute\H3\Connection as H3Connection;
use Croute\H3\Event as H3Event;
use Croute\Quic\Address;
use Croute\Quic\Connection as QuicConnection;

/**
 * H3 protocol handler. One instance per QUIC connection.
 *
 * Wraps a {@link QuicConnection} (libquiche) + on-demand
 * {@link H3Connection} (libquiche H3) and exposes the BayServer
 * ProtocolHandler shape so the rest of the framework
 * (InboundShip / TourRes / Tour) can drive it. Stream-level send
 * back-pressure is buffered as {@link PartialResponse} entries that
 * are flushed when libquiche says the stream is writable.
 *

 */
class QicProtocolHandler extends ProtocolHandler
{
    /**
     * Maximum UDP payload we ask libquiche to fit each datagram into.
     * Matches QicPacket::MAX_DATAGRAM_SIZE.
     */
    const MAX_DATAGRAM_SIZE = 1350;
    const PROTOCOL = "HTTP/3";

    public QuicConnection $con;
    public string $sender;          // "host:port" the datagram came from
    public Address $localAddr;
    public Address $peerAddr;
    public H3Config $h3Config;
    public Multiplexer $multiplexer;
    public ?H3Connection $h3con = null;

    /** @var array<int, list<PartialResponse>> stream id -> queued sends */
    public array $partialResponses = [];

    /** Scratch buffer reused across {@link postPackets} calls. */
    private string $sendScratch;
    /** RecvInfo / SendInfo opaque blob handed to libquiche. */
    private string $sendInfo;

    public function __construct(QicInboundHandler $inboundHandler,
        QuicConnection $con,
        string $senderAddr,
        Address $localAddr,
        Address $peerAddr,
        H3Config $h3Config,
        Multiplexer $multiplexer)
    {
        // Java's super(null, null, null, null, handler, true) — H3 doesn't
        // use the command pool; pass null for the PHP-specific CommandStore
        // slot (= ProtocolHandler::commandStore is ?CommandStore).
        parent::__construct(new QicNullPacketUnPacker(),
            new PacketPacker(),
            new QicNullCommandUnPacker(),
            new QicNullCommandPacker(),
            $inboundHandler,
            null,
            true);

        $this->con = $con;
        $this->sender = $senderAddr;
        $this->localAddr = $localAddr;
        $this->peerAddr = $peerAddr;
        $this->h3Config = $h3Config;
        $this->multiplexer = $multiplexer;
        $this->sendScratch = str_repeat("\0", self::MAX_DATAGRAM_SIZE);
        $this->sendInfo = Binding::newSendInfo();

        $inboundHandler->init($this);
    }

    public function __toString() : string
    {
        return $this->ship !== null ? (string)$this->ship : "h3";
    }

    //////////////////////////////////////////
    // Implements ProtocolHandler
    //////////////////////////////////////////

    public function protocol() : string { return self::PROTOCOL; }
    public function maxReqPacketDataSize() : int { return self::MAX_DATAGRAM_SIZE; }
    public function maxResPacketDataSize() : int { return self::MAX_DATAGRAM_SIZE; }

    /**
     * Override the base bytesReceived: H3 doesn't go through the
     * BayServer packet pipeline. The datagram bytes go straight into
     * libquiche, which then surfaces H3 events via poll().
     */
    public function bytesReceived(string $buf) : int
    {
        try {
            $n = $this->con->recv($buf, $this->peerAddr, $this->localAddr);
        } catch (CrouteError $e) {
            BayLog::debug("%s recv rejected: %s (code=%d)", $this, $e->getMessage(), $e->errCode);
            // RFC 9000 — drop the bad packet but keep the connection
            // alive; quiche may also have queued a CONNECTION_CLOSE
            //.
            return NextSocketAction::CONTINUE;
        }

        if ($n !== Binding::ERR_DONE) {
            $h3c = $this->http3Connection();
            if ($h3c !== null) {
                $this->processH3Connection($h3c);
            }
        }
        return NextSocketAction::CONTINUE;
    }

    public function reset() : void
    {
        $this->h3con = null;
        $this->partialResponses = [];
    }

    //////////////////////////////////////////
    // H3-specific helpers
    //////////////////////////////////////////

    /**
     * Lazy-construct the H3 connection once the QUIC handshake is
     * complete (or once early data is accepted).
     */
    public function http3Connection() : ?H3Connection
    {
        if ($this->h3con === null) {
            if ($this->con->isInEarlyData() || $this->con->isEstablished()) {
                BayLog::debug("%s Handshake done", $this);
                $this->h3con = new H3Connection($this->con, $this->h3Config);
                BayLog::debug("%s New H3 connection", $this);
            }
        }
        return $this->h3con;
    }

    private function processH3Connection(H3Connection $h3c) : void
    {
        try {
            while (($ev = $h3c->poll()) !== null) {
                $this->dispatchEvent($ev);
            }
        } catch (CrouteError $e) {
            BayLog::debug("%s h3 poll failed: %s (code=%d)", $this, $e->getMessage(), $e->errCode);
        }
        $this->flushWritable();
    }

    private function dispatchEvent(H3Event $ev) : void
    {
        /** @var QicCommandHandler $h */
        $h = $this->commandHandler;
        switch ($ev->type) {
            case 'headers':
                $h->handleHeaders(new CmdHeader($ev->streamId, $ev->headers ?? []));
                break;
            case 'data':
                $h->handleData(new CmdData($ev->streamId));
                break;
            case 'finished':
                $h->handleFinished(new CmdFinished($ev->streamId));
                break;
            default:
                BayLog::debug("%s stm#%d ignored event: %s", $this, $ev->streamId, $ev->type);
                break;
        }
    }

    private function flushWritable() : void
    {
        foreach ($this->con->writableStreams() as $stmId) {
            $this->onStreamWritable($stmId);
        }
    }

    private function onStreamWritable(int $stmId) : void
    {
        if (!isset($this->partialResponses[$stmId]))
            return;
        $parts =& $this->partialResponses[$stmId];

        BayLog::debug("%s stm#%d writable qlen=%d", $this, $stmId, count($parts));
        $listeners = [];

        try {
            foreach ($parts as $part) {
                if ($part->headers !== null) {
                    try {
                        $this->h3con->sendResponse($stmId, $part->headers, $part->fin);
                    } catch (CrouteError $e) {
                        if ($e->errCode === QuicheErrorCode::H3_ERR_STREAM_BLOCKED) {
                            BayLog::debug("%s stm#%d retry send header: blocked", $this, $stmId);
                            break;
                        }
                        throw new IOException("stm#$stmId h3: send header failed: {$e->getMessage()}({$e->errCode})");
                    }
                    BayLog::debug("%s stm#%d retry send header succeed", $this, $stmId);
                    $part->finished = true;
                } else {
                    $body = $part->body !== null
                        ? substr($part->body, $part->written, strlen($part->body) - $part->written)
                        : "";
                    try {
                        $n = $this->h3con->sendBody($stmId, $body, $part->fin);
                    } catch (CrouteError $e) {
                        BayLog::error("%s stm#%d h3: retry send body failed: %s(%d)", $this, $stmId, $e->getMessage(), $e->errCode);
                        break;
                    }
                    BayLog::trace("%s stm#%d retry send body %d bytes fin=%d", $this, $stmId, $n, $part->fin ? 1 : 0);
                    if ($n === 0) break;
                    $part->written += $n;
                    if ($part->body === null || $part->written >= strlen($part->body))
                        $part->finished = true;
                    if (!$part->finished)
                        break;
                }
            }

            // Remove finished parts from the front; stop at first unfinished.
            while (!empty($parts) && $parts[0]->finished) {
                $part = array_shift($parts);
                if ($part->listener !== null)
                    $listeners[] = $part->listener;
            }
        } catch (\Throwable $e) {
            BayLog::error_e($e);
            $parts = [];
        }

        foreach ($listeners as $lis) {
            ($lis)(true);
        }
        if (empty($parts))
            unset($this->partialResponses[$stmId]);

        $this->postPackets();
    }

    public function addPartialResponse(int $stmId, PartialResponse $part) : void
    {
        if (!isset($this->partialResponses[$stmId]))
            $this->partialResponses[$stmId] = [];
        $this->partialResponses[$stmId][] = $part;
    }

    /**
     * Drain every outbound datagram quiche has buffered onto the UDP
     * socket via the multiplexer. / Ruby version reuse a
     * single scratch + sendInfo across the loop.
     */
    public function postPackets() : bool
    {
        $posted = false;
        while (true) {
            if ($this->con->isClosed())
                break;
            try {
                $n = $this->con->send($this->sendScratch, $this->sendInfo);
            } catch (CrouteError $e) {
                BayLog::debug("%s send on closing connection: %s", $this, $e->getMessage());
                break;
            }
            if ($n === Binding::ERR_DONE)
                break;

            $pkt = substr($this->sendScratch, 0, $n);
            // flush=true because UDP sendto is atomic per-datagram; the
            // multiplexer has nothing useful to coalesce.
            $this->multiplexer->reqWrite($this->ship->rudder, $pkt, $this->sender, $pkt, true, function ($avail) { /* no-op */ });
            $posted = true;
        }
        return $posted;
    }

    public function closed() : bool { return $this->con->isClosed(); }
}

/**
 * A single buffered send waiting for the stream to become writable.
PartialResponse and Ruby
 * QicProtocolHandler::PartialResponse.
 */
class PartialResponse
{
    /** @var array<int, array{0:string,1:string}>|null */
    public ?array $headers;
    public ?string $body;
    public int $written;
    public bool $fin;
    /** @var callable|null */
    public $listener;
    public bool $finished;

    /**
     * @param array<int, array{0:string,1:string}>|null $headers
     */
    public function __construct(?array $headers = null, ?string $body = null, int $written = 0, bool $fin = false, $listener = null)
    {
        $this->headers = $headers;
        $this->body = $body !== null ? substr($body, $written, strlen($body) - $written) : null;
        $this->written = 0;
        $this->fin = $fin;
        $this->listener = $listener;
        $this->finished = false;
    }
}

/**
 * No-op PacketUnPacker to satisfy ProtocolHandler's typed field.
 * H3 bypasses the packet pipeline entirely -- bytesReceived is
 * overridden directly on QicProtocolHandler.
 */
class QicNullPacketUnPacker extends PacketUnPacker
{
    public function bytesReceived(string $bytes) : int
    {
        throw new Sink("QicNullPacketUnPacker should never be called");
    }
    public function reset() : void {}
}

/**
 * No-op CommandUnPacker (same rationale as QicNullPacketUnPacker).
 */
class QicNullCommandUnPacker extends CommandUnPacker
{
    public function packetReceived(Packet $pkt) : int
    {
        throw new Sink("QicNullCommandUnPacker should never be called");
    }
    public function reset() : void {}
}

/**
 * No-op CommandPacker. H3 sends responses through libquiche
 * (sendResponse / sendBody) rather than the BayServer Command
 * pipeline, so post() must never reach this packer. Provide the
 * stub so ProtocolHandler's typed field is satisfied.
 */
class QicNullCommandPacker extends CommandPacker
{
    public function __construct()
    {
        // Skip parent constructor entirely; its fields are never read.
    }
    public function reset() : void {}
}
