<?php

declare(strict_types=1);

namespace phpverse\Protocol;

/**
 * FastCGI 1.1 codec (record framing + name/value pair stream).
 *
 * Per spec, each record is laid out on the wire as:
 *
 *   typedef struct {
 *       unsigned char version;             // = FCGI_VERSION_1
 *       unsigned char type;
 *       unsigned char requestIdB1;         // high byte
 *       unsigned char requestIdB0;         // low byte
 *       unsigned char contentLengthB1;     // high byte
 *       unsigned char contentLengthB0;     // low byte
 *       unsigned char paddingLength;
 *       unsigned char reserved;
 *       unsigned char contentData[contentLength];
 *       unsigned char paddingData[paddingLength];
 *   } FCGI_Record;
 *
 * Padding is added so that (contentLength + paddingLength) is a
 * multiple of 8. The receiver discards the padding bytes.
 */
final class FcgiCodec
{
    private function __construct()
    {
    }

    /**
     * Blocking read of one FCGI record from a stream.
     *
     * Returns null when the stream is at EOF *before* the first byte
     * of a new header. A partial header (some bytes read then EOF) is
     * reported as a protocol error: the peer cut the connection
     * mid-record, which is observable on byte boundaries.
     *
     * @param  resource             $stream
     * @throws ProtocolException    on malformed framing
     */
    public static function readRecord($stream): ?Record
    {
        self::assertStream($stream);

        $header = self::readExact($stream, Constants::FCGI_HEADER_LEN, /* allowEof */ true);
        if ($header === null) {
            return null;
        }

        /** @var array{version:int,type:int,reqHi:int,reqLo:int,lenHi:int,lenLo:int,pad:int,reserved:int} $h */
        $h = unpack(
            'Cversion/Ctype/CreqHi/CreqLo/ClenHi/ClenLo/Cpad/Creserved',
            $header,
        );

        if ($h['version'] !== Constants::FCGI_VERSION_1) {
            throw new ProtocolException(sprintf(
                'unsupported FCGI version %d (expected %d)',
                $h['version'],
                Constants::FCGI_VERSION_1,
            ));
        }

        $requestId     = ($h['reqHi'] << 8) | $h['reqLo'];
        $contentLength = ($h['lenHi'] << 8) | $h['lenLo'];
        $paddingLength = $h['pad'];

        $content = $contentLength > 0
            ? self::readExact($stream, $contentLength, /* allowEof */ false)
            : '';

        if ($paddingLength > 0) {
            self::readExact($stream, $paddingLength, /* allowEof */ false);
        }

        return new Record($h['type'], $requestId, $content);
    }

    /**
     * Emit one FCGI record (header + content + padding) to a stream.
     *
     * @param  resource             $stream
     * @throws ProtocolException    on argument range violation or write failure
     */
    public static function writeRecord($stream, int $type, int $requestId, string $content): void
    {
        self::assertStream($stream);

        if ($type < 0 || $type > 0xFF) {
            throw new ProtocolException(sprintf('record type %d out of range [0,255]', $type));
        }
        if ($requestId < 0 || $requestId > 0xFFFF) {
            throw new ProtocolException(sprintf('requestId %d out of range [0,65535]', $requestId));
        }

        $contentLength = strlen($content);
        if ($contentLength > 0xFFFF) {
            // FCGI/1.1 has a hard 16-bit content-length cap; callers must
            // split larger payloads into multiple records (e.g. STDOUT).
            throw new ProtocolException(sprintf(
                'record content length %d exceeds FCGI maximum %d',
                $contentLength,
                0xFFFF,
            ));
        }

        $paddingLength = (8 - ($contentLength % 8)) % 8;

        $header = pack(
            'CCnnCC',
            Constants::FCGI_VERSION_1,
            $type,
            $requestId,
            $contentLength,
            $paddingLength,
            0, // reserved
        );

        $payload = $header . $content;
        if ($paddingLength > 0) {
            $payload .= str_repeat("\x00", $paddingLength);
        }

        self::writeAll($stream, $payload);
    }

    /**
     * Decode one or more concatenated FCGI_PARAMS payloads into a
     * name => value map.
     *
     * FCGI uses variable-length name/value lengths: if the top bit of
     * the first length byte is 1, the length is 4 bytes big-endian
     * (top bit cleared); else it's a 1-byte length. Name and value are
     * encoded independently.
     *
     * Later occurrences of the same name overwrite earlier ones,
     * matching the spec's "interpret bytes left-to-right" model.
     *
     * @return array<string,string>
     * @throws ProtocolException on truncated input
     */
    public static function readParams(string $paramsBytes): array
    {
        $out    = [];
        $offset = 0;
        $total  = strlen($paramsBytes);

        while ($offset < $total) {
            [$nameLen, $offset] = self::readNvLength($paramsBytes, $offset, $total);
            [$valLen,  $offset] = self::readNvLength($paramsBytes, $offset, $total);

            if ($offset + $nameLen + $valLen > $total) {
                throw new ProtocolException('FCGI_PARAMS truncated: name/value extend past payload');
            }

            $name  = substr($paramsBytes, $offset, $nameLen);
            $value = substr($paramsBytes, $offset + $nameLen, $valLen);
            $offset += $nameLen + $valLen;

            $out[$name] = $value;
        }

        return $out;
    }

    /**
     * Encode a name/value map into a single FCGI_PARAMS byte stream.
     *
     * Lengths >= 128 use the 4-byte form (top bit set); otherwise the
     * 1-byte form. Caller is responsible for splitting the result into
     * <=65535-byte records (see {@see writeRecord}).
     *
     * @param array<string,string> $kv
     */
    public static function writeParams(array $kv): string
    {
        $out = '';
        foreach ($kv as $name => $value) {
            // Keys can be ints in PHP arrays ("0" → 0). FCGI keys are
            // arbitrary bytes; coerce so callers don't have to.
            $name  = (string) $name;
            $value = (string) $value;
            $out  .= self::encodeNvLength(strlen($name))
                  .  self::encodeNvLength(strlen($value))
                  .  $name
                  .  $value;
        }
        return $out;
    }

    // ===============================================================
    // Internals
    // ===============================================================

    /**
     * @return array{0:int,1:int} [length, newOffset]
     */
    private static function readNvLength(string $buf, int $offset, int $total): array
    {
        if ($offset + 1 > $total) {
            throw new ProtocolException('FCGI_PARAMS truncated: missing length byte');
        }
        $first = ord($buf[$offset]);
        if (($first & 0x80) === 0) {
            return [$first, $offset + 1];
        }
        if ($offset + 4 > $total) {
            throw new ProtocolException('FCGI_PARAMS truncated: missing 4-byte length');
        }
        $b0 = $first & 0x7F;
        $b1 = ord($buf[$offset + 1]);
        $b2 = ord($buf[$offset + 2]);
        $b3 = ord($buf[$offset + 3]);
        $len = ($b0 << 24) | ($b1 << 16) | ($b2 << 8) | $b3;
        return [$len, $offset + 4];
    }

    private static function encodeNvLength(int $len): string
    {
        if ($len < 0) {
            throw new ProtocolException(sprintf('negative name/value length %d', $len));
        }
        if ($len < 0x80) {
            return chr($len);
        }
        if ($len > 0x7FFFFFFF) {
            throw new ProtocolException(sprintf(
                'name/value length %d exceeds FCGI maximum %d',
                $len,
                0x7FFFFFFF,
            ));
        }
        return pack('N', $len | 0x80000000);
    }

    /**
     * Read exactly $n bytes from $stream.
     *
     * If $allowEof is true *and* zero bytes have been consumed at the
     * first read attempt, returns null (clean EOF on a record boundary).
     * Any other short read or fread() failure raises ProtocolException.
     *
     * @param  resource $stream
     */
    private static function readExact($stream, int $n, bool $allowEof): ?string
    {
        $buf  = '';
        $need = $n;
        while ($need > 0) {
            $chunk = fread($stream, $need);
            if ($chunk === false) {
                throw new ProtocolException('fread() failed while reading FCGI record');
            }
            if ($chunk === '') {
                if ($allowEof && $buf === '') {
                    return null;
                }
                throw new ProtocolException(sprintf(
                    'unexpected EOF: needed %d more bytes (got %d of %d)',
                    $need,
                    strlen($buf),
                    $n,
                ));
            }
            $buf  .= $chunk;
            $need -= strlen($chunk);
        }
        return $buf;
    }

    /**
     * @param resource $stream
     */
    private static function writeAll($stream, string $data): void
    {
        $offset = 0;
        $len    = strlen($data);
        while ($offset < $len) {
            $written = fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new ProtocolException(sprintf(
                    'fwrite() failed at offset %d of %d',
                    $offset,
                    $len,
                ));
            }
            $offset += $written;
        }
    }

    /**
     * @param mixed $stream
     */
    private static function assertStream($stream): void
    {
        if (!is_resource($stream)) {
            throw new ProtocolException(sprintf(
                'expected stream resource, got %s',
                get_debug_type($stream),
            ));
        }
    }
}
