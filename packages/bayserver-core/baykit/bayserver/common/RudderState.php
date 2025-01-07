<?php

namespace baykit\bayserver\common;


use baykit\bayserver\BayLog;
use baykit\bayserver\rudder\Rudder;

class RudderState {
    public Rudder $rudder;
    public ?Transporter $transporter;
    public Multiplexer $multiplexer;

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

    public bool $accepting = false;
    public bool $connecting = false;

    public function __construct(Rudder $rd, Transporter $tp = null, int $timeoutSec = 0) {
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
        return "st(rd=#{" . $this->rudder . "} mpx=#{" . $this->multiplexer . "} tp=#{" . $this->transporter . "})";
    }

    public function access(): void {
        $this->lastAccessTime = time();
    }

    public function end(): void {
        $finale = true;
    }

    public function toString(): string {
        $str = "st(rd={$this->rudder} mpx={$this->multiplexer} tp={$this->transporter})";
        if ($this->closing)
            $str .= " closing";
        return $str;
    }
}
