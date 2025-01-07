<?php
namespace baykit\bayserver\common;


use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\util\DataConsumeListener;
use baykit\bayserver\util\Reusable;

interface Transporter extends Reusable
{
    public function init(): void;

    public function onConnected(Rudder $rd): int;

    public function onRead(Rudder $rd, string $data, ?string $adr): int;

    public function onError(Rudder $rd, \Exception $e);

    public function onClosed(Rudder $rd);

    public function reqConnect(Rudder $rd, string $addr): void;

    public function reqRead(Rudder $rd): void;

    public function reqWrite(Rudder $rd, string $buf, ?string $adr, $tag, ?callable $callback): void;

    public function reqClose(Rudder $rd): void;

    public function checkTimeout(Rudder $rd, int $durationSec): bool;

    public function getReadBufferSize(): int;

    public function secure(): bool;

    /**
     * print memory usage
     */
    public function printUsage(int $indent): void;
}