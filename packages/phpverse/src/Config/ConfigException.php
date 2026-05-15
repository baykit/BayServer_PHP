<?php

declare(strict_types=1);

namespace phpverse\Config;

/**
 * Thrown by {@see TomlParser} (or higher-level config loaders) when the
 * input is malformed or contains semantics we deliberately refuse to
 * support in our minimal TOML subset.
 *
 * The message format always includes the offending line number so
 * operators can `sed -n "Np"` straight to it.
 */
final class ConfigException extends \RuntimeException
{
}
