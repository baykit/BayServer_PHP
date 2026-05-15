<?php

declare(strict_types=1);

namespace phpverse\Embed;

use RuntimeException;

/**
 * Thrown by {@see ForkedWorker::spawn()} when a worker cannot be brought
 * up: bind failure, fork failure, the child not connecting back within
 * the configured timeout, or the child exiting before connecting.
 *
 * Library-mode hosts catch this to fail fast (or to back off and retry)
 * without conflating it with general runtime errors.
 */
final class SpawnException extends RuntimeException
{
}
