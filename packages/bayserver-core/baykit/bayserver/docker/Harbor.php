<?php
namespace baykit\bayserver\docker;

use baykit\bayserver\Sink;
use baykit\bayserver\util\Locale;

interface Harbor extends Docker
{
    const MULTIPLEXER_TYPE_SPIDER = 1;
    const MULTIPLEXER_TYPE_SPIN = 2;
    const MULTIPLEXER_TYPE_PIGEON = 3;
    const MULTIPLEXER_TYPE_JOB = 4;
    const MULTIPLEXER_TYPE_TAXI = 5;
    const MULTIPLEXER_TYPE_TRAIN = 6;

    const RECIPIENT_TYPE_SPIDER = 1;
    const RECIPIENT_TYPE_PIPE = 2;

    /** Default charset */
    public function charset() : string;

    /** Default locale */
    public function locale(): Locale;

    /** Number of grand agents */
    public function grandAgents(): int;

    /** Number of train runners */
    public function trainRunners(): int;

    /** Number of taxi runners */
    public function taxiRunners(): int;

    /** Max count of ships */
    public function maxShips(): int;

    /** Trouble docker */
    public function trouble(): ?Trouble;

    /** Socket timeout in seconds */
    public function socketTimeoutSec(): int;

    /** Keep-Alive timeout in seconds */
    public function keepTimeoutSec(): int;

    /** Trace req/res header flag */
    public function traceHeader(): bool;

    /** Internal buffer size of Tour */
    public function tourBufferSize(): int;

    /** File name to redirect stdout/stderr */
    public function redirectFile(): ?string;

    /** Port number of signal agent */
    public function controlPort(): int;

    /** Gzip compression flag */
    public function gzipComp(): bool;

    /** Multiplexer of Network I/O */
    public function netMultiplexer(): int;

    /** Multiplexer of File I/O */
    public function fileMultiplexer(): int;

    /** Multiplexer of Log output */
    public function logMultiplexer(): int;

    /** Multiplexer of CGI input */
    public function cgiMultiplexer(): int;

    /** Recipient */
    public function recipient(): int;

    /** PID file name */
    public function pidFile(): string;

    /** Multi core flag */
    public function multiCore(): bool;

}

function getMultiplexerTypeName(int $type): ?string {
    switch ($type) {
        case Harbor::MULTIPLEXER_TYPE_SPIDER:
            return "spider";
        case Harbor::MULTIPLEXER_TYPE_SPIN:
            return "spin";
        case Harbor::MULTIPLEXER_TYPE_PIGEON:
            return "pigeon";
        case Harbor::MULTIPLEXER_TYPE_JOB:
            return "job";
        case Harbor::MULTIPLEXER_TYPE_TAXI:
            return "taxi";
        case Harbor::MULTIPLEXER_TYPE_TRAIN:
            return "train";
        default:
            return null;
    }
}


function getMultiplexerType(string $type) : int {
    if($type != null)
        $type = strtolower($type);
    switch ($type) {
        case "spider":
            return Harbor::MULTIPLEXER_TYPE_SPIDER;
        case "spin":
            return Harbor::MULTIPLEXER_TYPE_SPIN;
        case "pigeon":
            return Harbor::MULTIPLEXER_TYPE_PIGEON;
        case "job":
            return Harbor::MULTIPLEXER_TYPE_JOB;
        case "taxi":
            return Harbor::MULTIPLEXER_TYPE_TAXI;
        case "train":
            return Harbor::MULTIPLEXER_TYPE_TRAIN;
        default:
            throw new \InvalidArgumentException();
    }
}

function getRecipientTypeName(int $type): ?string {
    switch ($type) {
        case Harbor::RECIPIENT_TYPE_SPIDER:
            return "spider";

        case Harbor::RECIPIENT_TYPE_PIPE:
            return "pipe";

        default:
            return null;
    }
}

function getRecipientType(?string $type) : int{
    if($type != null)
        $type = strtolower($type);
    switch ($type) {
        case "spider":
            return Harbor::RECIPIENT_TYPE_SPIDER;
        case "pipe":
            return Harbor::RECIPIENT_TYPE_PIPE;
        default:
            throw new \InvalidArgumentException();
    }
}