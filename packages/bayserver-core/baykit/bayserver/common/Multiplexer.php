<?php

namespace baykit\bayserver\common;

use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\util\DataConsumeListener;

/**
 * Managements I/O Multiplexing
 * (Possible implementations include the select system call, event APIs, or threading)
 */
interface Multiplexer
{
    public function addRudderState(Rudder $rd, RudderState $st): void;

    public function removeRudderState(Rudder $rd): void;

    public function getRudderState(Rudder $rd): ?RudderState;

    public function getTransporter(Rudder $rd): Transporter;

    public function reqAccept(Rudder $rd): void;

    public function reqConnect(Rudder $rd, string $addr): void;

    public function reqRead(Rudder $rd): void;

    /**
     * Queue a write. Returns whether the rudder's write buffer still
     * has room (= remaining() <= shipBufferSize). When false the caller
     * should stop submitting and resume only when the consume listener
     * fires with bufferAvailable=true.
     *
     * @param bool $flush  When false the multiplexer may keep the data
     *                    in its writeQueue without registering OP_WRITE
     *                    until enough pending bytes accumulate. Callers
     *                    should pass true on the final write of a
     *                    logical message so the peer actually sees it.
     *                    Mirrors
     */
    public function reqWrite(Rudder $rd, string $buf, ?string $adr, $tag, bool $flush, ?callable $callback): bool;

    /**
     * Direct Boarding: queue a file-rudder transfer onto the write queue.
     * Mirrors the Multiplexer.reqTransfer The PHP
     * SpiderMultiplexer implementation throws Sink because PHP has no
     * non-blocking sendfile equivalent.
     */
    public function reqTransfer(Rudder $rd, Rudder $fileRd, int $ofs, int $len, ?callable $callback): void;

    public function reqEnd(Rudder $rd): void;

    public function reqClose(Rudder $rd): void;

    public function cancelRead(RudderState $st): void;

    public function cancelWrite(RudderState $st): void;

    public function nextAccept(RudderState $state): void;
    public function nextRead(RudderState $st): void;
    public function nextWrite(RudderState $st): void;

    public function shutdown(): void;

    public function isNonBlocking(): bool;
    public function useAsyncAPI(): bool;

    public function consumeOldestUnit(RudderState $st): bool;
    public function closeRudder(RudderState $st): void;

    public function isBusy(): bool;
    public function onBusy(): void;
    public function onFree(): void;
}