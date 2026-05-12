<?php

namespace baykit\bayserver\common;


use baykit\bayserver\BayLog;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\Sink;
use baykit\bayserver\util\Counter;
use baykit\bayserver\util\Reusable;

class RudderState implements Reusable {
    const STATE_ID_NOCHECK = -1;

    public ?Rudder $rudder = null;
    public ?Transporter $transporter = null;
    public ?Multiplexer $multiplexer = null;

    /**
     * Monotonic id used by the GrandAgent letter dispatch loop to detect
     * stale letters whose state has been returned to the pool and reused.
id.
     */
    public static ?Counter $idCounter = null;
    public int $id = 0;

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
        if (self::$idCounter === null)
            self::$idCounter = new Counter();
        $this->id = self::$idCounter->next();
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

    public function toString(): string {
        $str = "st(rd={$this->rudder} mpx={$this->multiplexer} tp={$this->transporter})";
        if ($this->closing)
            $str.= " closing";
        return $str;
    }

    ////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////

    /**
     * Used by the GrandAgent letter dispatch loop. Throws Sink if a stale
     * letter carries a stateId that no longer matches the current state
     * (i.e. the state was Returned to the pool and rented out for a
     * different rudder). checkStateId.
     */
    public function checkStateId(int $checkId) : void
    {
        if ($checkId === self::STATE_ID_NOCHECK)
            return;
        if ($checkId !== $this->id) {
            throw new Sink("%s Invalid state id : %d", $this, $checkId);
        }
    }

    public function reset(): void
    {
        $this->id = 0;
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
