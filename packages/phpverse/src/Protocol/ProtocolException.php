<?php

declare(strict_types=1);

namespace phpverse\Protocol;

use RuntimeException;

/**
 * Thrown on malformed FCGI framing or unexpected stream state.
 *
 * Distinct from generic RuntimeException so callers can catch
 * protocol errors without swallowing unrelated runtime failures.
 */
final class ProtocolException extends RuntimeException
{
}
