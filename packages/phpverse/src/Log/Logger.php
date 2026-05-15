<?php

declare(strict_types=1);

namespace phpverse\Log;

/**
 * Structured logger for daemon-mode (and forked-worker) operation.
 *
 * Output sink:
 *   - null filePath  → STDERR
 *   - string filePath → append-mode file, fd kept open. reopen() closes
 *     and reopens for logrotate integration (SIGUSR2).
 *
 * Line format:
 *   [YYYY-MM-DDTHH:MM:SS+ZZ:ZZ] [level] [worker-<id>] message {context_json}
 *
 * `[worker-<id>]` is omitted when no workerId is set (= the supervisor
 * parent's own logger). `context_json` is only appended when a non-empty
 * context array is passed.
 *
 * Threshold filtering happens at log time. Names map to numeric levels;
 * a message is emitted only if its level <= threshold (higher levels =
 * more verbose; debug=4 emits everything, error=1 emits only errors).
 */
final class Logger
{
    public const LEVEL_ERROR = 1;
    public const LEVEL_WARN  = 2;
    public const LEVEL_INFO  = 3;
    public const LEVEL_DEBUG = 4;

    private const LEVEL_NAMES = [
        self::LEVEL_ERROR => 'error',
        self::LEVEL_WARN  => 'warn',
        self::LEVEL_INFO  => 'info',
        self::LEVEL_DEBUG => 'debug',
    ];

    private const LEVEL_LOOKUP = [
        'error' => self::LEVEL_ERROR,
        'warn'  => self::LEVEL_WARN,
        'info'  => self::LEVEL_INFO,
        'debug' => self::LEVEL_DEBUG,
    ];

    private int $threshold;

    private ?string $filePath;

    /** @var resource|null */
    private $fileHandle = null;

    private ?int $workerId = null;

    public function __construct(int $threshold, ?string $filePath = null)
    {
        $this->threshold = $threshold;
        $this->filePath  = $filePath;
        if ($filePath !== null) {
            $this->openFile();
        }
    }

    public static function levelFromName(string $name): int
    {
        $name = strtolower($name);
        if (!isset(self::LEVEL_LOOKUP[$name])) {
            throw new \InvalidArgumentException("unknown log level: $name");
        }
        return self::LEVEL_LOOKUP[$name];
    }

    public function setThreshold(int $threshold): void
    {
        $this->threshold = $threshold;
    }

    public function getThreshold(): int
    {
        return $this->threshold;
    }

    public function setWorkerId(?int $id): void
    {
        $this->workerId = $id;
    }

    /**
     * Re-open the underlying log file (no-op when logging to STDERR).
     *
     * Logrotate's standard "rename + signal" flow leaves us writing to a
     * file that has been moved aside; reopen() closes the moved file
     * and opens a fresh one at the original path.
     */
    public function reopen(): void
    {
        if ($this->filePath === null) {
            return;
        }
        if (is_resource($this->fileHandle)) {
            @fclose($this->fileHandle);
            $this->fileHandle = null;
        }
        $this->openFile();
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->emit(self::LEVEL_ERROR, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warn(string $message, array $context = []): void
    {
        $this->emit(self::LEVEL_WARN, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->emit(self::LEVEL_INFO, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->emit(self::LEVEL_DEBUG, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function log(int $level, string $message, array $context = []): void
    {
        $this->emit($level, $message, $context);
    }

    /**
     * Convenience for callers that have a level NAME string.
     *
     * @param array<string,mixed> $context
     */
    public function logByName(string $level, string $message, array $context = []): void
    {
        $this->emit(self::levelFromName($level), $message, $context);
    }

    /** @param array<string,mixed> $context */
    private function emit(int $level, string $message, array $context): void
    {
        if ($level > $this->threshold) {
            return;
        }

        $name = self::LEVEL_NAMES[$level] ?? '?';
        $line = sprintf('[%s] [%s] ', date('c'), $name);
        if ($this->workerId !== null) {
            $line .= sprintf('[worker-%d] ', $this->workerId);
        }
        $line .= $message;
        if ($context !== []) {
            $json = @json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json)) {
                $line .= ' ' . $json;
            }
        }
        $line .= "\n";

        $sink = $this->fileHandle;
        if (!is_resource($sink)) {
            $sink = STDERR;
        }
        @fwrite($sink, $line);
    }

    private function openFile(): void
    {
        if ($this->filePath === null) {
            return;
        }
        $fh = @fopen($this->filePath, 'a');
        if (!is_resource($fh)) {
            // Fall back to STDERR rather than crashing the daemon — log
            // file errors must never take down the supervisor.
            @fwrite(STDERR, sprintf(
                "[%s] [error] phpverse: failed to open log file %s; falling back to stderr\n",
                date('c'),
                $this->filePath,
            ));
            $this->fileHandle = null;
            $this->filePath   = null;
            return;
        }
        $this->fileHandle = $fh;
    }

    public function __destruct()
    {
        if (is_resource($this->fileHandle)) {
            @fclose($this->fileHandle);
            $this->fileHandle = null;
        }
    }
}
