<?php

declare(strict_types=1);

namespace phpverse\Cli;

use phpverse\Config\ConfigException;
use phpverse\Config\TomlParser;

/**
 * CLI dispatcher for the `phpverse` binary. Three operating modes:
 *
 *   phpverse                   (no args / --help) → help
 *   phpverse --version          → version line
 *   phpverse --worker --socket ... --worker-id N → library-mode worker
 *      (= same logic as worker.php, exposed for hand-debug)
 *   phpverse --workers N --listen URI [...]      → daemon mode
 *
 * Exit codes follow MANUAL §7 (0 clean, 1 runtime, 2 config, 64 usage).
 */
final class Main
{
    /** @param list<string> $argv */
    public static function run(array $argv): int
    {
        $args = array_slice($argv, 1);

        if ($args === [] || in_array('--help', $args, true) || in_array('-h', $args, true)) {
            self::printHelp();
            return 0;
        }
        if (in_array('--version', $args, true)) {
            fwrite(STDOUT, 'phpverse ' . Version::STRING . "\n");
            return 0;
        }

        if (in_array('--worker', $args, true)) {
            return self::runWorkerMode($args);
        }

        return self::runDaemonMode($args);
    }

    /**
     * Worker mode (= what the daemon execs internally for hand-debug).
     * For Phase 5 the daemon does NOT actually exec this path (it forks
     * without exec), so this branch exists only so an operator can run
     * a single worker for debugging.
     *
     * @param list<string> $args
     */
    private static function runWorkerMode(array $args): int
    {
        $opts = self::parseArgs($args, [
            'worker'    => 'flag',
            'socket'    => 'str',
            'worker-id' => 'int',
            'prepend'   => 'str',
        ]);
        if ($opts === null) {
            return 64;
        }

        $socket = $opts['socket'] ?? getenv('PHPVERSE_SOCKET') ?: null;
        if (!is_string($socket) || $socket === '') {
            fwrite(STDERR, "phpverse: --worker requires --socket PATH (or PHPVERSE_SOCKET env)\n");
            return 2;
        }

        $workerId = (int) ($opts['worker-id'] ?? (getenv('PHPVERSE_WORKER_ID') ?: 0));
        $prepend  = $opts['prepend'] ?? (getenv('PHPVERSE_PREPEND') ?: null);

        $errno = 0;
        $err   = '';
        $conn  = @stream_socket_client('unix://' . $socket, $errno, $err, 5.0);
        if (!is_resource($conn)) {
            fwrite(STDERR, sprintf(
                "phpverse: connect %s failed: %s (errno=%d)\n",
                $socket,
                $err,
                $errno,
            ));
            return 1;
        }

        $workerOpts = ['workerId' => $workerId];
        if (is_string($prepend) && $prepend !== '') {
            $workerOpts['prepend'] = $prepend;
        }

        try {
            $w = new \phpverse\Worker($conn, $workerOpts);
            $w->loop();
            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "phpverse: worker crashed: %s: %s\n",
                $e::class,
                $e->getMessage(),
            ));
            return 1;
        }
    }

    /** @param list<string> $args */
    private static function runDaemonMode(array $args): int
    {
        $opts = self::parseArgs($args, [
            'workers'              => 'int',
            'listen'               => 'str',
            'max-requests'         => 'int',
            'max-script-seconds'   => 'int',
            'prepend'              => 'str',
            'php'                  => 'str',
            'pid-file'             => 'str',
            'worker-uid'           => 'str',
            'worker-gid'           => 'str',
            'log-level'            => 'str',
            'log-file'             => 'str',
            'config'               => 'str',
        ]);
        if ($opts === null) {
            return 64;
        }

        // Resolve config-file path: --config > $PHPVERSE_CONFIG > /etc fallback.
        $configPath = $opts['config'] ?? (getenv('PHPVERSE_CONFIG') ?: null);
        if ($configPath === null && is_file('/etc/phpverse/phpverse.conf')) {
            $configPath = '/etc/phpverse/phpverse.conf';
        }

        $fileVals = [];
        if (is_string($configPath) && $configPath !== '') {
            try {
                $fileVals = self::flattenConfig(TomlParser::parseFile($configPath));
            } catch (ConfigException $e) {
                fwrite(STDERR, "phpverse: config error: " . $e->getMessage() . "\n");
                return 2;
            }
        }

        // Precedence resolver: CLI > env > config-file > default.
        $resolve = static function (string $cliKey, ?string $envName, string $fileKey, mixed $default = null) use ($opts, $fileVals): mixed {
            if (array_key_exists($cliKey, $opts)) {
                return $opts[$cliKey];
            }
            if ($envName !== null) {
                $v = getenv($envName);
                if ($v !== false && $v !== '') {
                    return $v;
                }
            }
            if (array_key_exists($fileKey, $fileVals)) {
                return $fileVals[$fileKey];
            }
            return $default;
        };

        $workers = $resolve('workers', null, 'workers');
        if ($workers === null) {
            fwrite(STDERR, "phpverse: --workers N is required (or set workers = N in config)\n");
            self::printUsage(STDERR);
            return 2;
        }
        $workers = (int) $workers;
        if ($workers < 1) {
            fwrite(STDERR, "phpverse: --workers must be > 0\n");
            return 2;
        }

        $listen = $resolve('listen', null, 'listen');
        if (!is_string($listen) || $listen === '') {
            fwrite(STDERR, "phpverse: --listen URI is required (or set listen = \"...\" in config)\n");
            self::printUsage(STDERR);
            return 2;
        }

        $maxRequests = (int) $resolve('max-requests', null, 'max_requests', 0);
        if ($maxRequests < 0) {
            fwrite(STDERR, "phpverse: --max-requests must be >= 0\n");
            return 2;
        }

        $maxScriptSeconds = (int) $resolve('max-script-seconds', null, 'max_script_seconds', 30);
        if ($maxScriptSeconds < 1) {
            fwrite(STDERR, "phpverse: --max-script-seconds must be > 0\n");
            return 2;
        }

        $logLevel = $resolve('log-level', 'PHPVERSE_LOG_LEVEL', 'log_level', 'info');
        $logFile  = $resolve('log-file',  null,                 'log_file',  null);
        $pidFile  = $resolve('pid-file',  'PHPVERSE_PID_FILE',  'pid_file',  null);
        $workerUid = $resolve('worker-uid', null, 'security.worker_uid', null);
        $workerGid = $resolve('worker-gid', null, 'security.worker_gid', null);
        $prepend   = $resolve('prepend',  null, 'prepend',  null);
        $php       = $resolve('php',      null, 'php',      null);

        // Validate log level early so the daemon doesn't get a surprise.
        if (!is_string($logLevel) || !in_array(strtolower($logLevel), ['error', 'warn', 'info', 'debug'], true)) {
            fwrite(STDERR, "phpverse: --log-level must be one of error|warn|info|debug\n");
            return 2;
        }

        $daemonOpts = [
            'workers'          => $workers,
            'listen'           => (string) $listen,
            'maxRequests'      => $maxRequests,
            'maxScriptSeconds' => $maxScriptSeconds,
            'prepend'          => is_string($prepend) && $prepend !== '' ? $prepend : null,
            'php'              => is_string($php) && $php !== '' ? $php : null,
            'pidFile'          => is_string($pidFile) && $pidFile !== '' ? $pidFile : null,
            'workerUid'        => is_string($workerUid) || is_int($workerUid) ? $workerUid : null,
            'workerGid'        => is_string($workerGid) || is_int($workerGid) ? $workerGid : null,
            'logLevel'         => strtolower((string) $logLevel),
            'logFile'          => is_string($logFile) && $logFile !== '' ? $logFile : null,
            'configPath'       => is_string($configPath) && $configPath !== '' ? $configPath : null,
        ];

        $daemon = new DaemonMode($daemonOpts);
        return $daemon->run();
    }

    /**
     * Flatten TomlParser's two-level output into a flat key=>value map.
     * Section keys become "section.key" (e.g. "security.worker_uid").
     * Unknown top-level / section names are dropped with a warning so a
     * typo doesn't silently take effect.
     *
     * @param array<string, mixed> $parsed
     * @return array<string, scalar>
     */
    private static function flattenConfig(array $parsed): array
    {
        $knownTop = [
            'listen', 'workers', 'max_requests', 'max_script_seconds',
            'prepend', 'php', 'log_level', 'log_file', 'pid_file',
        ];
        $knownSection = [
            'security' => ['worker_uid', 'worker_gid'],
        ];
        $flat = [];
        foreach ($parsed as $key => $value) {
            if (is_array($value)) {
                // section
                if (!isset($knownSection[$key])) {
                    fwrite(STDERR, "phpverse: warning: unknown config section [$key], ignored\n");
                    continue;
                }
                foreach ($value as $sub => $subVal) {
                    if (!in_array($sub, $knownSection[$key], true)) {
                        fwrite(STDERR, "phpverse: warning: unknown config key [$key].$sub, ignored\n");
                        continue;
                    }
                    $flat["$key.$sub"] = $subVal;
                }
                continue;
            }
            if (!in_array($key, $knownTop, true)) {
                fwrite(STDERR, "phpverse: warning: unknown config key '$key', ignored\n");
                continue;
            }
            $flat[$key] = $value;
        }
        return $flat;
    }

    /**
     * Tiny argv parser. Supports `--flag` (bool) and `--key VALUE` or
     * `--key=VALUE` forms. Unknown options → print error + usage,
     * return null (caller exits 64).
     *
     * @param list<string>            $args
     * @param array<string,string>    $spec  name → 'flag'|'int'|'str'
     * @return array<string,mixed>|null
     */
    private static function parseArgs(array $args, array $spec): ?array
    {
        $out = [];
        $n   = count($args);
        for ($i = 0; $i < $n; $i++) {
            $a = $args[$i];
            if (!str_starts_with($a, '--')) {
                fwrite(STDERR, "phpverse: unexpected positional argument: $a\n");
                self::printUsage(STDERR);
                return null;
            }
            $name = substr($a, 2);
            $val  = null;
            if (str_contains($name, '=')) {
                [$name, $val] = explode('=', $name, 2);
            }
            if (!isset($spec[$name])) {
                fwrite(STDERR, "phpverse: unknown flag: --$name\n");
                self::printUsage(STDERR);
                return null;
            }
            $kind = $spec[$name];
            if ($kind === 'flag') {
                $out[$name] = true;
                continue;
            }
            if ($val === null) {
                if ($i + 1 >= $n) {
                    fwrite(STDERR, "phpverse: --$name requires a value\n");
                    return null;
                }
                $val = $args[++$i];
            }
            if ($kind === 'int') {
                if (!preg_match('/^-?\d+$/', $val)) {
                    fwrite(STDERR, "phpverse: --$name expects an integer, got: $val\n");
                    return null;
                }
                $out[$name] = (int) $val;
            } else {
                $out[$name] = $val;
            }
        }
        return $out;
    }

    private static function printHelp(): void
    {
        fwrite(STDOUT, self::helpText());
    }

    /** @param resource $stream */
    private static function printUsage($stream): void
    {
        fwrite($stream, "usage: phpverse --workers N --listen URI [options]\n");
        fwrite($stream, "       phpverse --worker --socket PATH [--worker-id N]\n");
        fwrite($stream, "       phpverse --version | --help\n");
    }

    private static function helpText(): string
    {
        $body = <<<TXT
phpverse {{VERSION}} — FastCGI worker pool for PHP

Usage:
  phpverse --workers N --listen URI [options]    daemon mode
  phpverse --worker --socket PATH [--worker-id N]  internal worker mode
  phpverse --version                              print version
  phpverse --help                                 this help

Daemon mode options:
  --workers N              number of worker processes (required)
  --listen URI             unix:///path or tcp://host:port (required)
  --max-requests N         retire each worker after N requests (default 0 = never)
  --max-script-seconds N   per-request hard kill threshold (default 30)
  --prepend PATH           script each worker requires once on startup
  --php PATH               PHP CLI binary (informational; daemon forks without exec)
  --pid-file PATH          write parent PID to this file (unlinked on exit)
  --worker-uid USER        drop workers to this user (name or uid; requires root)
  --worker-gid GROUP       drop workers to this group (name or gid; requires root)
  --log-level LEVEL        error|warn|info|debug (default info)
  --log-file PATH          append-mode log file (default stderr)
  --config PATH            TOML config file (CLI flags override its values)

Signals (daemon):
  SIGTERM / SIGINT  graceful shutdown
  SIGQUIT           immediate shutdown (SIGKILL workers)
  SIGCHLD           reap + respawn dead workers
  SIGUSR1           graceful reload (drain + respawn workers)
  SIGUSR2           reopen log file (for logrotate)
  SIGHUP            re-read --config (log_level, max_requests only)

Environment variables (overridden by CLI flags):
  PHPVERSE_CONFIG     config file path
  PHPVERSE_LOG_LEVEL  log level
  PHPVERSE_PID_FILE   pid file path

See MANUAL.md for the full operator-facing reference.
TXT;
        return str_replace('{{VERSION}}', Version::STRING, $body) . "\n";
    }
}
