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
        $sum = 0;
        foreach ($this->writeQueue as $u) {
            $sum += strlen($u->buf);
        }
        return $sum;
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
    }
}
