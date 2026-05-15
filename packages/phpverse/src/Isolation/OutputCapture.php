<?php

declare(strict_types=1);

namespace phpverse\Isolation;

use phpverse\Protocol\Constants;
use phpverse\Protocol\FcgiCodec;

/**
 * Translates user PHP output into FCGI_STDOUT / FCGI_STDERR records.
 *
 * On the first body byte we prepend the CGI header preamble built from
 * http_response_code() + headers_list(). Subsequent chunks are body
 * only. finish() always emits a valid preamble even if the script
 * produced zero bytes, then sends the empty-STDOUT marker + END_REQUEST.
 */
final class OutputCapture
{
    /** Max FCGI_STDOUT payload per record (FCGI/1.1 16-bit cap). */
    private const FCGI_RECORD_CAP = 0xFFF8;

    /** @var resource */
    private $conn;

    private int $requestId;

    private int $chunkSize;

    private bool $headersEmitted = false;

    private bool $obActive = false;

    private bool $finished = false;

    /** @param resource $conn */
    public function __construct($conn, int $requestId, int $chunkSize = 8192)
    {
        $this->conn      = $conn;
        $this->requestId = $requestId;
        $this->chunkSize = $chunkSize;
    }

    public function start(): void
    {
        ob_start(function (string $chunk, int $phase): string {
            // ob_* sometimes calls with empty chunks on phase changes;
            // ignore them so we don't emit a header-only STDOUT prematurely.
            if ($chunk === '') {
                return '';
            }
            $payload = '';
            if (!$this->headersEmitted) {
                $payload .= $this->buildPreamble();
                $this->headersEmitted = true;
            }
            $payload .= $chunk;
            $this->writeStdout($payload);
            return '';
        }, $this->chunkSize);
        $this->obActive = true;
    }

    public function finish(int $defaultStatus = 200): void
    {
        if ($this->finished) {
            return;
        }
        if ($this->obActive) {
            @ob_end_flush();
            $this->obActive = false;
        }
        if (!$this->headersEmitted) {
            $this->writeStdout($this->buildPreamble($defaultStatus));
            $this->headersEmitted = true;
        }
        $this->emitEnd(0);
        $this->finished = true;
    }

    /** Discard buffered output, emit a 4xx/5xx response, then END_REQUEST. */
    public function emitErrorResponse(int $status, string $body, ?string $stderrTrace = null): void
    {
        if ($this->finished) {
            return;
        }
        if ($this->obActive) {
            // ob_clean drops the buffer without invoking the callback;
            // ob_end_clean tears down so further user output goes nowhere
            // (the request is over).
            @ob_end_clean();
            $this->obActive = false;
        }

        $preamble = sprintf(
            "Status: %d %s\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: %d\r\n\r\n",
            $status,
            self::reasonPhrase($status),
            strlen($body),
        );
        $this->writeStdout($preamble . $body);
        $this->headersEmitted = true;

        if ($stderrTrace !== null && $stderrTrace !== '') {
            $this->writeStderr($stderrTrace);
        }

        $this->emitEnd($status >= 500 ? 1 : 0);
        $this->finished = true;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    public function getRequestId(): int
    {
        return $this->requestId;
    }

    private function buildPreamble(int $defaultStatus = 200): string
    {
        $lines     = headers_list();
        $hasStatus = false;
        foreach ($lines as $line) {
            if (stripos($line, 'Status:') === 0) {
                $hasStatus = true;
                break;
            }
        }
        $code = http_response_code();
        if (!is_int($code) || $code <= 0) {
            $code = $defaultStatus;
        }
        $out = '';
        if (!$hasStatus) {
            $out .= sprintf("Status: %d %s\r\n", $code, self::reasonPhrase($code));
        }
        foreach ($lines as $line) {
            $out .= $line . "\r\n";
        }
        $out .= "\r\n";
        return $out;
    }

    private function writeStdout(string $payload): void
    {
        $offset = 0;
        $len    = strlen($payload);
        while ($offset < $len) {
            $chunk = substr($payload, $offset, self::FCGI_RECORD_CAP);
            FcgiCodec::writeRecord($this->conn, Constants::FCGI_STDOUT, $this->requestId, $chunk);
            $offset += strlen($chunk);
        }
    }

    private function writeStderr(string $payload): void
    {
        $offset = 0;
        $len    = strlen($payload);
        while ($offset < $len) {
            $chunk = substr($payload, $offset, self::FCGI_RECORD_CAP);
            FcgiCodec::writeRecord($this->conn, Constants::FCGI_STDERR, $this->requestId, $chunk);
            $offset += strlen($chunk);
        }
        // Empty STDERR marks end of that substream per FCGI/1.1 §5.2.
        FcgiCodec::writeRecord($this->conn, Constants::FCGI_STDERR, $this->requestId, '');
    }

    private function emitEnd(int $appStatus): void
    {
        // End-of-stream marker on STDOUT (FCGI/1.1 §5.2).
        FcgiCodec::writeRecord($this->conn, Constants::FCGI_STDOUT, $this->requestId, '');
        $body = pack('NCxxx', $appStatus, Constants::FCGI_REQUEST_COMPLETE);
        FcgiCodec::writeRecord($this->conn, Constants::FCGI_END_REQUEST, $this->requestId, $body);
    }

    private static function reasonPhrase(int $code): string
    {
        return match ($code) {
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            418 => "I'm a teapot",
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => $code >= 500 ? 'Internal Server Error' : 'OK',
        };
    }
}
