<?php

declare(strict_types=1);

namespace phpverse\Config;

/**
 * Hand-rolled, intentionally tiny TOML subset parser.
 *
 * Supported:
 *   - comments: `# ...` (line tail, after a value, or whole line)
 *   - blank lines
 *   - top-level `key = value` pairs
 *   - section headers `[section]` (exactly one level deep)
 *   - values:
 *       * integers   42 / -7
 *       * booleans   true / false
 *       * strings    "..." with backslash escapes \n \t \r \\ \" only
 *
 * Deliberately refused (a ConfigException is thrown):
 *   - arrays:           `key = [1, 2]`
 *   - inline tables:    `key = {a = 1}`
 *   - array-of-tables:  `[[xs]]`
 *   - nested tables:    `[a.b]`
 *   - multi-line strings:  `"""..."""` or `'''...'''`
 *   - literal strings:  `'...'`
 *   - date/time literals
 *   - hex/oct/bin/float integers
 *   - unicode escapes:  `\uXXXX`
 *
 * This is more than enough for the MANUAL §2.4 example and saves us a
 * heavy dependency.
 *
 * Return shape:
 *   parse() returns array<string, scalar|array<string, scalar>> — top-
 *   level scalars at the outer level, section-scoped scalars nested
 *   one deep under the section name.
 */
final class TomlParser
{
    /**
     * @return array<string, int|bool|string|array<string, int|bool|string>>
     */
    public static function parseFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigException("config file not found or unreadable: $path");
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new ConfigException("config file read failed: $path");
        }
        return self::parse($content);
    }

    /**
     * @return array<string, int|bool|string|array<string, int|bool|string>>
     */
    public static function parse(string $input): array
    {
        $result      = [];
        $currentSection = null;   // null = top-level
        $lines       = preg_split("/\r\n|\n|\r/", $input);
        if ($lines === false) {
            $lines = [$input];
        }

        foreach ($lines as $idx => $rawLine) {
            $lineno = $idx + 1;
            $line   = self::stripCommentAndTrim($rawLine, $lineno);
            if ($line === '') {
                continue;
            }

            if ($line[0] === '[') {
                $currentSection = self::parseSectionHeader($line, $lineno);
                if (!isset($result[$currentSection])) {
                    $result[$currentSection] = [];
                } elseif (!is_array($result[$currentSection])) {
                    throw new ConfigException(sprintf(
                        'line %d: section [%s] conflicts with earlier top-level key of the same name',
                        $lineno,
                        $currentSection,
                    ));
                }
                continue;
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                throw new ConfigException(sprintf(
                    'line %d: expected "key = value" or "[section]", got: %s',
                    $lineno,
                    $line,
                ));
            }
            $key = trim(substr($line, 0, $eq));
            $rhs = trim(substr($line, $eq + 1));
            if ($key === '') {
                throw new ConfigException("line $lineno: empty key");
            }
            if (!preg_match('/^[A-Za-z0-9_\-]+$/', $key)) {
                throw new ConfigException(sprintf(
                    'line %d: unsupported key syntax (only [A-Za-z0-9_-]+ allowed): %s',
                    $lineno,
                    $key,
                ));
            }
            $value = self::parseValue($rhs, $lineno);

            if ($currentSection === null) {
                if (array_key_exists($key, $result)) {
                    throw new ConfigException("line $lineno: duplicate key: $key");
                }
                $result[$key] = $value;
            } else {
                /** @var array<string, int|bool|string> $bucket */
                $bucket = $result[$currentSection];
                if (array_key_exists($key, $bucket)) {
                    throw new ConfigException(sprintf(
                        'line %d: duplicate key in [%s]: %s',
                        $lineno,
                        $currentSection,
                        $key,
                    ));
                }
                $bucket[$key] = $value;
                $result[$currentSection] = $bucket;
            }
        }
        return $result;
    }

    /**
     * Strip a trailing `# comment` from a line, handling the case where
     * `#` is inside a "..." string literal (don't truncate there).
     *
     * Returns the trimmed remainder. Whole-line comments / blank lines
     * collapse to ''.
     */
    private static function stripCommentAndTrim(string $line, int $lineno): string
    {
        // Easy case: the line is whitespace or starts with `#`.
        $trimmed = ltrim($line);
        if ($trimmed === '' || $trimmed[0] === '#') {
            return '';
        }

        $inString = false;
        $escaped  = false;
        $end      = strlen($line);
        for ($i = 0; $i < strlen($line); $i++) {
            $c = $line[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($c === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($c === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($c === '"') {
                $inString = true;
                continue;
            }
            if ($c === '#') {
                $end = $i;
                break;
            }
        }
        if ($inString) {
            throw new ConfigException("line $lineno: unterminated string");
        }
        return trim(substr($line, 0, $end));
    }

    private static function parseSectionHeader(string $line, int $lineno): string
    {
        // Refuse [[arrays-of-tables]] explicitly.
        if (str_starts_with($line, '[[')) {
            throw new ConfigException("line $lineno: array-of-tables [[...]] not supported");
        }
        if (!str_ends_with($line, ']')) {
            throw new ConfigException("line $lineno: malformed section header: $line");
        }
        $name = trim(substr($line, 1, -1));
        if ($name === '') {
            throw new ConfigException("line $lineno: empty section header");
        }
        if (str_contains($name, '.')) {
            throw new ConfigException("line $lineno: nested tables [a.b] not supported: $name");
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $name)) {
            throw new ConfigException(sprintf(
                'line %d: unsupported section name (only [A-Za-z0-9_-]+ allowed): %s',
                $lineno,
                $name,
            ));
        }
        return $name;
    }

    /**
     * @return int|bool|string
     */
    private static function parseValue(string $rhs, int $lineno)
    {
        if ($rhs === '') {
            throw new ConfigException("line $lineno: empty value");
        }
        $c = $rhs[0];

        if ($c === '[') {
            throw new ConfigException("line $lineno: array literals are not supported");
        }
        if ($c === '{') {
            throw new ConfigException("line $lineno: inline tables are not supported");
        }
        if ($c === "'") {
            throw new ConfigException("line $lineno: literal strings ('...') are not supported (use \"...\")");
        }
        if (str_starts_with($rhs, '"""')) {
            throw new ConfigException("line $lineno: multi-line strings are not supported");
        }

        if ($c === '"') {
            return self::parseString($rhs, $lineno);
        }
        if ($rhs === 'true') {
            return true;
        }
        if ($rhs === 'false') {
            return false;
        }
        if (preg_match('/^-?\d+$/', $rhs)) {
            // Refuse stray underscores / hex / oct / bin: TOML allows
            // underscores as digit separators and 0x/0o/0b prefixes,
            // but supporting that is overkill for our config schema.
            return (int) $rhs;
        }
        // Numbers with extra structure (floats, _ separator, 0x...) and
        // unquoted strings all fall here; reject loudly so the operator
        // notices instead of silently mis-parsing.
        throw new ConfigException(sprintf(
            'line %d: unrecognized value (only int / bool / "string" supported): %s',
            $lineno,
            $rhs,
        ));
    }

    private static function parseString(string $rhs, int $lineno): string
    {
        if (strlen($rhs) < 2 || !str_ends_with($rhs, '"')) {
            throw new ConfigException("line $lineno: unterminated string");
        }
        $body = substr($rhs, 1, -1);
        $out  = '';
        $i    = 0;
        $n    = strlen($body);
        while ($i < $n) {
            $c = $body[$i];
            if ($c === '\\') {
                if ($i + 1 >= $n) {
                    throw new ConfigException("line $lineno: dangling backslash in string");
                }
                $esc = $body[$i + 1];
                $out .= match ($esc) {
                    'n'    => "\n",
                    't'    => "\t",
                    'r'    => "\r",
                    '"'    => '"',
                    '\\'   => '\\',
                    default => throw new ConfigException(sprintf(
                        'line %d: unsupported escape sequence \\%s (only \\n \\t \\r \\" \\\\)',
                        $lineno,
                        $esc,
                    )),
                };
                $i += 2;
                continue;
            }
            if ($c === '"') {
                // Should have been caught by the "must end with ..." check,
                // so a bare `"` mid-string means an embedded unescaped quote.
                throw new ConfigException("line $lineno: unescaped \" inside string");
            }
            $out .= $c;
            $i++;
        }
        return $out;
    }
}
