<?php

declare(strict_types=1);

namespace phpverse\Cli;

use phpverse\Config\ConfigException;
use phpverse\Config\TomlParser;
use phpverse\Log\Logger;
use phpverse\Pool;

/**
 * Daemon-mode entry point: bind the listen socket, install signal
 * handlers, fork workers via {@see Pool}, then run a tiny supervisor
 * loop that dispatches signals (reap-on-SIGCHLD, shutdown-on-SIGTERM,
 * graceful-reload-on-SIGUSR1, reopen-logs-on-SIGUSR2, reload-config-
 * on-SIGHUP, immediate-shutdown-on-SIGQUIT).
 *
 * The supervisor itself does no per-request work — it only watches
 * for child events, exactly like php-fpm's master process.
 */
final class DaemonMode
{
    /** @var array<string,mixed> */
    private array $opts;

    private bool $shutdownFlag = false;
    private bool $reapPendingFlag = false;
    private bool $immediateShutdownFlag = false;
    private bool $reloadFlag = false;
    private bool $reopenLogsFlag = false;
    private bool $configReloadFlag = false;

    /** @var resource|null */
    private $listenSocket = null;

    private ?string $unixSocketPath = null;

    private ?string $pidFilePath = null;
    private bool $pidFileWritten = false;

    private ?Logger $logger = null;

    private ?Pool $pool = null;

    /** @param array<string,mixed> $opts */
    public function __construct(array $opts)
    {
        $this->opts = $opts;
    }

    public function run(): int
    {
        // Logger must come up before anything that might log.
        try {
            $this->logger = new Logger(
                Logger::levelFromName((string) ($this->opts['logLevel'] ?? 'info')),
                isset($this->opts['logFile']) && is_string($this->opts['logFile']) && $this->opts['logFile'] !== ''
                    ? $this->opts['logFile']
                    : null,
            );
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, 'phpverse: ' . $e->getMessage() . "\n");
            return 2;
        }

        // Resolve uid/gid from possibly-string opts before fork so the
        // user sees the error early.
        try {
            [$uid, $gid] = $this->resolvePrivilegeDrop();
        } catch (\RuntimeException $e) {
            $this->logger->error($e->getMessage());
            return 2;
        }

        try {
            $this->bindListen();
        } catch (\Throwable $e) {
            $this->logger->error('phpverse: ' . $e->getMessage());
            return 1;
        }

        try {
            $this->writePidFile();
        } catch (\RuntimeException $e) {
            $this->logger->error($e->getMessage());
            $this->closeListen();
            return 1;
        }

        $this->installSignalHandlers();

        $workerOpts = [
            'maxRequests' => (int) $this->opts['maxRequests'],
            'logger'      => $this->logger,
        ];
        if ($uid !== null) {
            $workerOpts['workerUid'] = $uid;
        }
        if ($gid !== null) {
            $workerOpts['workerGid'] = $gid;
        }
        if (isset($this->opts['prepend']) && is_string($this->opts['prepend']) && $this->opts['prepend'] !== '') {
            $workerOpts['prepend'] = $this->opts['prepend'];
        }
        putenv('PHPVERSE_MAX_SCRIPT_SECONDS=' . (int) $this->opts['maxScriptSeconds']);
        if (isset($this->opts['php']) && is_string($this->opts['php']) && $this->opts['php'] !== '') {
            putenv('PHPVERSE_PHP=' . $this->opts['php']);
        }

        $this->pool = new Pool($this->listenSocket, (int) $this->opts['workers'], $workerOpts);

        $this->logger->info(sprintf(
            'starting daemon: workers=%d listen=%s max_requests=%d log_level=%s',
            (int) $this->opts['workers'],
            (string) $this->opts['listen'],
            (int) $this->opts['maxRequests'],
            (string) $this->opts['logLevel'],
        ));

        $this->pool->start();

        // Supervisor loop. Signal handlers set flags; we poll them
        // here so the work runs on a stable stack (not inside the
        // signal handler).
        while (true) {
            pcntl_signal_dispatch();

            if ($this->immediateShutdownFlag) {
                $this->logger->warn('SIGQUIT: immediate shutdown');
                $this->pool->shutdownImmediate();
                break;
            }
            if ($this->shutdownFlag) {
                break;
            }
            if ($this->reapPendingFlag) {
                $this->reapPendingFlag = false;
                $this->pool->reapDeadWorkers();
            }
            if ($this->reloadFlag) {
                $this->reloadFlag = false;
                $this->logger->info('SIGUSR1: graceful reload');
                $this->pool->gracefulReload();
            }
            if ($this->reopenLogsFlag) {
                $this->reopenLogsFlag = false;
                $this->logger->info('SIGUSR2: reopen logs');
                $this->logger->reopen();
                $this->pool->reopenLogs();
            }
            if ($this->configReloadFlag) {
                $this->configReloadFlag = false;
                $this->reloadConfig();
            }
            // 100ms cadence: tight enough that workers respawn
            // quickly on crash but loose enough to leave CPU for the
            // workers themselves.
            usleep(100_000);
        }

        $this->logger->info('shutting down');
        if (!$this->immediateShutdownFlag) {
            $this->pool->shutdown(SIGTERM);
        }
        $this->closeListen();
        $this->unlinkPidFile();
        $this->logger->info('exit clean');
        return 0;
    }

    private function bindListen(): void
    {
        $uri = (string) $this->opts['listen'];

        if (str_starts_with($uri, 'unix://')) {
            $path = substr($uri, strlen('unix://'));
            if ($path === '') {
                throw new \RuntimeException("listen: empty unix:// path");
            }
            if (file_exists($path)) {
                @unlink($path);
            }
            $url = 'unix://' . $path;
            $this->unixSocketPath = $path;
        } elseif (str_starts_with($uri, 'tcp://')) {
            $url = $uri;
        } else {
            throw new \RuntimeException("listen: unsupported scheme: $uri (use unix:///path or tcp://host:port)");
        }

        $errno  = 0;
        $errstr = '';
        // PHP's stream_socket_server() defaults to a listen backlog of 32,
        // which fills instantly under FCGI load (clients see EAGAIN on
        // connect() and the daemon appears to return errors). Raise the
        // backlog explicitly; also enable SO_REUSEPORT on TCP listeners so
        // multiple daemon instances can share a port if needed (it is a
        // no-op / silently ignored on AF_UNIX).
        $ctx = stream_context_create([
            'socket' => [
                'backlog'      => 65535,
                'so_reuseport' => true,
            ],
        ]);
        $sock = @stream_socket_server(
            $url,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $ctx,
        );
        if (!is_resource($sock)) {
            throw new \RuntimeException("stream_socket_server($url) failed: $errstr (errno=$errno)");
        }
        $this->listenSocket = $sock;
    }

    private function closeListen(): void
    {
        if (is_resource($this->listenSocket)) {
            @fclose($this->listenSocket);
            $this->listenSocket = null;
        }
        if ($this->unixSocketPath !== null && file_exists($this->unixSocketPath)) {
            @unlink($this->unixSocketPath);
        }
    }

    /**
     * @return array{0:?int,1:?int} resolved [uid, gid]
     */
    private function resolvePrivilegeDrop(): array
    {
        $uid = $this->opts['workerUid'] ?? null;
        $gid = $this->opts['workerGid'] ?? null;
        if ($uid === null && $gid === null) {
            return [null, null];
        }
        if (posix_geteuid() !== 0) {
            throw new \RuntimeException(
                'phpverse: --worker-uid / --worker-gid require root at startup'
            );
        }
        $resolvedUid = null;
        $resolvedGid = null;
        if ($uid !== null) {
            if (is_int($uid) || (is_string($uid) && preg_match('/^\d+$/', $uid))) {
                $resolvedUid = (int) $uid;
            } else {
                $pw = posix_getpwnam((string) $uid);
                if ($pw === false) {
                    throw new \RuntimeException("phpverse: unknown user: $uid");
                }
                $resolvedUid = (int) $pw['uid'];
            }
        }
        if ($gid !== null) {
            if (is_int($gid) || (is_string($gid) && preg_match('/^\d+$/', $gid))) {
                $resolvedGid = (int) $gid;
            } else {
                $gr = posix_getgrnam((string) $gid);
                if ($gr === false) {
                    throw new \RuntimeException("phpverse: unknown group: $gid");
                }
                $resolvedGid = (int) $gr['gid'];
            }
        }
        return [$resolvedUid, $resolvedGid];
    }

    private function writePidFile(): void
    {
        $path = $this->opts['pidFile'] ?? null;
        if (!is_string($path) || $path === '') {
            return;
        }
        // Stale-pid detection: if the file exists and contains a live PID,
        // refuse to start (= another daemon owns this slot). If the PID is
        // dead, take over.
        if (file_exists($path)) {
            $existing = @file_get_contents($path);
            if (is_string($existing)) {
                $pid = (int) trim($existing);
                if ($pid > 0 && @posix_kill($pid, 0)) {
                    throw new \RuntimeException(
                        "phpverse: pid file $path is held by live PID $pid"
                    );
                }
                $this->logger->warn("taking over stale pid file $path (PID $pid not running)");
            }
        }
        $ok = @file_put_contents($path, getmypid() . "\n");
        if ($ok === false) {
            throw new \RuntimeException("phpverse: cannot write pid file $path");
        }
        $this->pidFilePath = $path;
        $this->pidFileWritten = true;
    }

    private function unlinkPidFile(): void
    {
        if ($this->pidFileWritten && $this->pidFilePath !== null && file_exists($this->pidFilePath)) {
            @unlink($this->pidFilePath);
        }
    }

    /**
     * Re-read the config file (if a path was given) and apply runtime-
     * mutable knobs. Currently log_level and max_requests are mutable;
     * workers / listen / privilege require a full restart.
     */
    private function reloadConfig(): void
    {
        $path = $this->opts['configPath'] ?? null;
        if (!is_string($path) || $path === '') {
            $this->logger->warn('SIGHUP: no --config in use, ignoring reload');
            return;
        }
        $this->logger->info("SIGHUP: re-reading $path");
        try {
            $parsed = TomlParser::parseFile($path);
        } catch (ConfigException $e) {
            $this->logger->error('SIGHUP: config parse failed: ' . $e->getMessage());
            return;
        }

        if (array_key_exists('log_level', $parsed) && is_string($parsed['log_level'])) {
            try {
                $newLevel = Logger::levelFromName($parsed['log_level']);
                $this->logger->setThreshold($newLevel);
                $this->logger->info('SIGHUP: log_level → ' . $parsed['log_level']);
            } catch (\InvalidArgumentException $e) {
                $this->logger->error('SIGHUP: ' . $e->getMessage());
            }
        }
        if (array_key_exists('max_requests', $parsed) && is_int($parsed['max_requests'])) {
            if ($this->pool !== null) {
                $this->pool->setMaxRequests((int) $parsed['max_requests']);
                $this->logger->info('SIGHUP: max_requests → ' . (int) $parsed['max_requests']);
            }
        }

        // Warn about non-mutable changes.
        $immutable = ['workers', 'listen', 'pid_file', 'log_file'];
        foreach ($immutable as $k) {
            if (array_key_exists($k, $parsed)) {
                $this->logger->warn("SIGHUP: '$k' is not runtime-mutable (full restart required), ignored");
            }
        }
        if (isset($parsed['security']) && is_array($parsed['security'])) {
            foreach (['worker_uid', 'worker_gid'] as $k) {
                if (array_key_exists($k, $parsed['security'])) {
                    $this->logger->warn("SIGHUP: 'security.$k' is not runtime-mutable, ignored");
                }
            }
        }
    }

    private function installSignalHandlers(): void
    {
        pcntl_async_signals(true);
        $term = function (int $signo): void {
            $this->shutdownFlag = true;
        };
        pcntl_signal(SIGTERM, $term);
        pcntl_signal(SIGINT,  $term);
        pcntl_signal(SIGQUIT, function (int $signo): void {
            $this->immediateShutdownFlag = true;
            $this->shutdownFlag = true;
        });
        pcntl_signal(SIGCHLD, function (int $signo): void {
            $this->reapPendingFlag = true;
        });
        pcntl_signal(SIGUSR1, function (int $signo): void {
            $this->reloadFlag = true;
        });
        pcntl_signal(SIGUSR2, function (int $signo): void {
            $this->reopenLogsFlag = true;
        });
        pcntl_signal(SIGHUP, function (int $signo): void {
            $this->configReloadFlag = true;
        });
    }
}
