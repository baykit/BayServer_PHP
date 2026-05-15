<?php

declare(strict_types=1);

namespace phpverse\Protocol;

/**
 * One decoded FCGI 1.1 record.
 *
 * The structural padding bytes are not represented here; they are
 * consumed on decode and added on encode by {@see FcgiCodec}.
 */
final class Record
{
    public function __construct(
        public readonly int $type,
        public readonly int $requestId,
        public readonly string $content,
    ) {
    }
}
