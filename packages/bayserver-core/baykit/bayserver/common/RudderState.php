<?php

namespace baykit\bayserver\common;


use baykit\bayserver\BayLog;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\util\Reusable;

class RudderState implements Reusable {
    public ?Rudder $rudder = null;
    public ?Transporter $transporter = null;
    public ?Multiplexer $multiplexer = null;

    public int $lastAccessTime = 0;
    public bool $closing = false;
    public string $readBuf = "";
    public int $bufSize = 0;
    public array $writeQueue = [];
    /**
     * Running sum of pending bytes across {@link $writeQueue}.
     * Replaces the O(N) iteration in remaining() that ran on every
     * reqWrite to decide flush=true. Multiplexers maintain it: add
     * on enqueue and subtract on full consume.
     */
    public int $writeQueueBytes = 0;
    public bool $handshaking = false;
    public bool $reading = false;
    public bool $writing = false;
    public int $bytesRead = 0;
    public int $bytesWrote = 0;
    public bool $closed = false;
    public bool $finale = false;
    public int $timeoutSec = 0;
    public int $writeTryCount = 0;
    /** Direct Boarding marker -- mirrors upstream's RudderState.skipFormalities */
    public bool $skipFormalities = false;

    public bool $accepting = false;
    public bool $connecting = false;

    /**
     * If true, the SpiderMultiplexer re-arms TCP_QUICKACK on this socket
     * after every read so the kernel sends the ACK immediately instead
     * of holding it on the delayed-ACK timer (~40ms on Linux). Only
     * meaningful for warp upstream connections to backends that keep
     * Nagle on (= php-fpm and friends): the 40ms delayed-ACK timer
     * combined with backend Nagle stalls multi-MSS responses badly.
     *
     * Inbound (= client-facing) sockets don't need this -- the client
     * is a wrk / browser / load balancer that sets TCP_NODELAY itself,
     * and setsockopt per read measurably costs CPU at 50k+ rps -- so
     * warp code sets the flag explicitly when wiring up the upstream
     * socket, leaving inbound sockets untouched.
     */
    public bool $quickAck = false;
    /**
     * True while this state is enqueued in SpiderMultiplexer's
     * tryWriteList. Used to dedup pushes from
     * back-to-back reqWrite calls on the same rudder; without it,
     * a 1MB body's 16 chunked sendResContent calls each push the
     * same state, and the drain replays onWritable 16 times, firing
     * 16x as many WroteLetters as there are units to pop.
     */
    public bool $inTryWriteList = false;

    public function __construct(?Rudder $rd = null, ?Transporter $tp = null, int $timeoutSec = 0) {
        if ($rd !== null) {
            $this->init($rd, $tp, $timeoutSec);
        }
    }

    public function init(Rudder $rd, ?Transporter $tp = null, int $timeoutSec = 0) : void
    {
        $this->rudder = $rd;
        $this->transporter = $tp;
        $this->closed = false;
        $this->timeoutSec = $timeoutSec;
        if ($tp != null) {
            $this->handshaking = $tp->secure();
            $this->bufSize = $tp->getReadBufferSize();
        }
        else {
            $this->handshaking = false;
            $this->bufSize = 8192;
        }
    }

    public function __toString(): string
    {
        return "st(rd=#{". $this->rudder. "} mpx=#{". $this->multiplexer. "} tp=#{". $this->transporter. "})";
    }

    public function access(): void {
        $this->lastAccessTime = time();
    }

    public function end(): void {
        $this->finale = true;
    }

    /**
     * Total bytes pending in the write queue. Used by SpiderMultiplexer's
     * flush-batching decision: if remaining() crosses
     * bufSize, register OP_WRITE even when the caller requested flush=false.
     */
    public function remaining(): int
    {
        // O(1) via the writeQueueBytes counter the multiplexers
        // maintain. The counter slightly
        // overestimates during partial writes because the
        // consumeOldestUnit decrement only fires when the unit
        // fully drains; the overestimate is harmless for the
        // back-pressure threshold check.
        return $this->writeQueueBytes;
    }

    /**
     * Whether the per-rudder write buffer still has room. Returns true
     * when remaining() <= shipBufferSize, false once the queue has
     * grown past the harbor-configured ship buffer. bufferAvailable(). Drives the back-pressure
     * the TourRes / Ship layer propagates to consume listeners.
     */
    public function bufferAvailable(): bool
    {
        return $this->remaining() <= \baykit\bayserver\BayServer::$harbor->shipBufferSize();
    }

    public function toString(): string {
        $str = "st(rd={$this->rudder} mpx={$this->multiplexer} tp={$this->transporter})";
        if ($this->closing)
            $str.= " closing";
        return $str;
    }

    ////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////

    public function reset(): void
    {
        $this->rudder = null;
        $this->transporter = null;
        $this->multiplexer = null;

        $this->lastAccessTime = 0;
        $this->closing = false;
        $this->readBuf = "";
        $this->bufSize = 0;
        $this->writeQueue = [];
        $this->writeQueueBytes = 0;
        $this->handshaking = false;
        $this->reading = false;
        $this->writing = false;
        $this->bytesRead = 0;
        $this->bytesWrote = 0;
        $this->closed = false;
        $this->finale = false;
        $this->timeoutSec = 0;
        $this->writeTryCount = 0;
        $this->accepting = false;
        $this->connecting = false;
        $this->skipFormalities = false;
        $this->inTryWriteList = false;
        $this->quickAck = false;
    }
}
