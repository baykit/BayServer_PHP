<?php

declare(strict_types=1);

namespace phpverse\Embed;

/**
 * Library-mode helper: spawn one PHPVerse worker as a child process,
 * give the caller back a connected FCGI stream, and own the child's
 * lifecycle (signal, reap, cleanup).
 *
 * The flow is the same pattern SPEC §3.1 / §6.1 describe:
 *   1. Parent bind+listen on a per-worker Unix socket.
 *   2. fork().
 *   3. Child exec()s `php worker.php` with PHPVERSE_SOCKET pointing at
 *      that socket; child connects back.
 *   4. Parent accept()s, unlinks the socket file, and hands the
 *      connected stream to the host.
 *
 * The host then drives the FCGI conversation directly on
 * `$worker->sock`. ForkedWorker manages only the process / IPC plumbing,
 * not the wire protocol.
 */
final class ForkedWorker
{
    /**
     * Per the Linux kernel, sun_path is 108 bytes including the NUL
     * terminator. Stay conservatively below it so we don't depend on
     * libc behavior at the edge.
     */
    private const SOCK_PATH_MAX = 104;

    /** @var resource|null connected stream to the worker (null after close()). */
    public mixed $sock;

    public readonly int $pid;

    public readonly int $workerId;

    private bool $reaped = false;

    private bool $closed = false;

    /**
     * @param resource $sock
     */
    private function __construct($sock, int $pid, int $workerId)
    {
        $this->sock     = $sock;
        $this->pid      = $pid;
        $this->workerId = $workerId;
    }

    /**
     * Spawn one worker child process. See class doc for the protocol;
     * see SPEC §6.1 for the wire-level expectations.
     *
     * @param array<string,mixed> $opts {
     *     workerPhp:        string  (required) absolute path to worker.php
     *     php:              string  (default PHP_BINARY)
     *     workerId:         int     (default 0)
     *     prepend:          ?string (default null) → PHPVERSE_PREPEND
     *     maxRequests:      int     (default 0)    → PHPVERSE_MAX_REQUESTS
     *     socketDir:        string  (default sys_get_temp_dir())
     *     env:              array<string,string> (default [])
     *     cwd:              ?string (default parent cwd)
     *     spawnTimeoutMs:   int     (default 5000)
     * }
     */
    public static function spawn(array $opts): self
    {
        self::assertExtensionsLoaded();

        $workerPhp = (string) ($opts['workerPhp'] ?? '');
        if ($workerPhp === '') {
            throw new \InvalidArgumentException("ForkedWorker::spawn: 'workerPhp' is required");
        }
        // We intentionally do NOT pre-check is_file($workerPhp) here.
        // The child's pcntl_exec failure path (exit 127) plus the
        // accept timeout is the canonical "spawn failed" signal — and
        // covering it that way exercises the same cleanup path that a
        // real PHP-binary-missing failure would hit.

        $php            = (string) ($opts['php']            ?? PHP_BINARY);
        $workerId       = (int)    ($opts['workerId']       ?? 0);
        $prepend        = $opts['prepend']     ?? null;
        $maxRequests    = (int)    ($opts['maxRequests']    ?? 0);
        $socketDir      = (string) ($opts['socketDir']      ?? sys_get_temp_dir());
        $extraEnv       = (array)  ($opts['env']            ?? []);
        $cwd            = $opts['cwd']         ?? null;
        $spawnTimeoutMs = (int)    ($opts['spawnTimeoutMs'] ?? 5000);

        if ($spawnTimeoutMs <= 0) {
            throw new \InvalidArgumentException("ForkedWorker::spawn: 'spawnTimeoutMs' must be > 0");
        }

        $sockPath = self::makeSocketPath($socketDir);

        $listenSock = self::bindListenSocket($sockPath);

        $pid = pcntl_fork();
        if ($pid === -1) {
            @fclose($listenSock);
            @unlink($sockPath);
            throw new SpawnException('pcntl_fork() failed');
        }

        if ($pid === 0) {
            // ----- CHILD -----
            // Parent's listening fd is no use to us; close it so we
            // don't keep it open after exec (it'd be inherited but
            // unused, which masks errors during testing).
            @fclose($listenSock);

            $childEnv = $extraEnv;
            $childEnv['PHPVERSE_SOCKET']    = $sockPath;
            $childEnv['PHPVERSE_WORKER_ID'] = (string) $workerId;
            if ($prepend !== null && $prepend !== '') {
                $childEnv['PHPVERSE_PREPEND'] = (string) $prepend;
            }
            // SPEC §6.5: forward the env var even if the v1 Worker
            // doesn't yet honour it, for forward-compat with Phase 5+.
            $childEnv['PHPVERSE_MAX_REQUESTS'] = (string) $maxRequests;

            // Inherit PATH so the child PHP can find its own SAPI libs.
            if (!isset($childEnv['PATH'])) {
                $parentPath = getenv('PATH');
                if (is_string($parentPath) && $parentPath !== '') {
                    $childEnv['PATH'] = $parentPath;
                }
            }

            if (is_string($cwd) && $cwd !== '') {
                @chdir($cwd);
            }

            // pcntl_exec replaces the current process image. If it
            // returns, exec failed (e.g. $php binary missing): exit
            // 127 so the parent's accept-timeout / waitpid sees a
            // clean failure signal.
            @pcntl_exec($php, [$workerPhp], $childEnv);
            fwrite(STDERR, "phpverse: pcntl_exec failed for $php $workerPhp\n");
            exit(127);
        }

        // ----- PARENT -----
        try {
            $sock = self::acceptChild($listenSock, $pid, $sockPath, $spawnTimeoutMs);
        } catch (\Throwable $e) {
            @fclose($listenSock);
            if (file_exists($sockPath)) {
                @unlink($sockPath);
            }
            self::killAndReap($pid);
            throw $e;
        }

        @fclose($listenSock);
        // We accepted exactly one connection; the path can go. The
        // already-connected stream survives the unlink because the
        // peer's reference keeps the kernel-side socket alive.
        if (file_exists($sockPath)) {
            @unlink($sockPath);
        }

        // Hand the host a non-blocking stream so their event loop can
        // poll it without blocking other workers.
        stream_set_blocking($sock, false);

        return new self($sock, $pid, $workerId);
    }

    /**
     * Signal the worker. Does not wait, does not reap. Idempotent: a
     * no-op if the child is already gone.
     */
    public function kill(int $sig = SIGTERM): void
    {
        if ($this->reaped) {
            return;
        }
        // posix_kill returns false if the process doesn't exist; that's
        // fine — caller's semantic is "best-effort signal".
        @posix_kill($this->pid, $sig);
    }

    /**
     * Poll waitpid(WNOHANG) until either the child is reaped or the
     * timeout expires. Returns the child's exit status (clean integer
     * if it exited normally; raw status otherwise) or null on timeout.
     *
     * Multiple calls after a successful reap return the cached status
     * (the kernel forgets reaped children, so we have to).
     */
    public function waitForExit(int $timeoutMs = 5000): ?int
    {
        if ($this->reaped) {
            return $this->cachedExitStatus;
        }

        $deadline = self::nowMs() + max(0, $timeoutMs);
        $sleepUs  = 1000; // start at 1ms, grow to cap below
        while (true) {
            $status = 0;
            $r = pcntl_waitpid($this->pid, $status, WNOHANG);
            if ($r === $this->pid) {
                $this->reaped = true;
                $this->cachedExitStatus = pcntl_wifexited($status)
                    ? pcntl_wexitstatus($status)
                    : $status;
                return $this->cachedExitStatus;
            }
            if ($r === -1) {
                // ECHILD: somebody else (or a prior SIGCHLD handler)
                // already reaped it. We can't recover the exit status
                // in that case, so just mark reaped.
                $this->reaped = true;
                $this->cachedExitStatus = null;
                return null;
            }
            if (self::nowMs() >= $deadline) {
                return null;
            }
            usleep($sleepUs);
            if ($sleepUs < 20000) {
                $sleepUs *= 2;
            }
        }
    }

    private ?int $cachedExitStatus = null;

    /**
     * Cheap liveness probe. posix_kill(pid, 0) returns true iff the
     * process exists and we have permission to signal it.
     *
     * Note: if the child has exited but not yet been reaped, it's a
     * zombie and posix_kill returns true. To get true "alive in the
     * functional sense", call waitForExit(0) first; this method is the
     * "did I forget to reap?" check.
     */
    public function isAlive(): bool
    {
        if ($this->reaped) {
            return false;
        }
        // Non-blocking reap so a zombified child is observed as dead.
        $status = 0;
        $r = pcntl_waitpid($this->pid, $status, WNOHANG);
        if ($r === $this->pid) {
            $this->reaped = true;
            $this->cachedExitStatus = pcntl_wifexited($status)
                ? pcntl_wexitstatus($status)
                : $status;
            return false;
        }
        if ($r === -1) {
            $this->reaped = true;
            return false;
        }
        return @posix_kill($this->pid, 0);
    }

    /**
     * Tear down: close the IPC stream, SIGTERM the child, give it up
     * to 1s to drain, then SIGKILL + reap. Safe to call repeatedly.
     */
    public function close(): void
    {
        if ($this->closed) {
            // Even after close(), the destructor may still call us:
            // make sure the second call is a true no-op.
            return;
        }
        $this->closed = true;

        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->sock = null;

        if ($this->reaped) {
            return;
        }

        // Graceful first: SIGTERM, then a brief drain window for the
        // worker to fall out of its FCGI loop (it sees EOF on the
        // connection we just closed and exits in §6.3 fashion).
        @posix_kill($this->pid, SIGTERM);
        if ($this->waitForExit(1000) !== null) {
            return;
        }

        // Stubborn: force-kill, then reap so the parent doesn't
        // accumulate zombies.
        @posix_kill($this->pid, SIGKILL);
        $this->waitForExit(2000);
    }

    /**
     * Defensive cleanup if the host leaks the instance: we promise the
     * caller no zombies. Suppress everything — throwing out of a
     * destructor is a recipe for fatal-during-shutdown errors.
     */
    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
            // intentionally swallowed
        }
    }

    // -----------------------------------------------------------------
    // internals
    // -----------------------------------------------------------------

    private static function assertExtensionsLoaded(): void
    {
        foreach (['pcntl', 'posix'] as $ext) {
            if (!extension_loaded($ext)) {
                throw new SpawnException(
                    "ForkedWorker requires the '$ext' extension; not loaded in this PHP build"
                );
            }
        }
    }

    private static function makeSocketPath(string $socketDir): string
    {
        if ($socketDir === '' || !is_dir($socketDir)) {
            throw new SpawnException("ForkedWorker: socketDir not a directory: $socketDir");
        }
        $path = rtrim($socketDir, '/') . '/phpverse-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sock';
        if (strlen($path) > self::SOCK_PATH_MAX) {
            throw new SpawnException(sprintf(
                "ForkedWorker: socket path too long (%d > %d): %s",
                strlen($path),
                self::SOCK_PATH_MAX,
                $path,
            ));
        }
        return $path;
    }

    /**
     * @return resource the listening socket
     */
    private static function bindListenSocket(string $sockPath)
    {
        // stream_socket_server() refuses to bind on an existing path.
        // We just generated a fresh random name, but be defensive.
        if (file_exists($sockPath)) {
            @unlink($sockPath);
        }

        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_server(
            'unix://' . $sockPath,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        if (!is_resource($sock)) {
            throw new SpawnException(sprintf(
                "ForkedWorker: stream_socket_server(unix://%s) failed: %s (errno=%d)",
                $sockPath,
                $errstr,
                $errno,
            ));
        }
        return $sock;
    }

    /**
     * Wait for the child to connect back; on timeout / child-exit-
     * before-connect, raise SpawnException so the caller's catch+retry
     * has a single boundary type.
     *
     * @param  resource $listenSock
     * @return resource the accepted client stream
     */
    private static function acceptChild($listenSock, int $childPid, string $sockPath, int $spawnTimeoutMs)
    {
        $deadline = self::nowMs() + $spawnTimeoutMs;
        // Poll in modest slices so we can also notice the child dying
        // before it ever connects (= exec failure, missing worker.php,
        // missing pcntl, etc.). stream_socket_accept's own timeout is
        // a wall-clock bound on the whole accept call; we want a
        // periodic chance to waitpid in between.
        while (true) {
            $remainingMs = $deadline - self::nowMs();
            if ($remainingMs <= 0) {
                throw new SpawnException(sprintf(
                    "ForkedWorker: worker pid=%d did not connect within %dms (socket=%s)",
                    $childPid,
                    $spawnTimeoutMs,
                    $sockPath,
                ));
            }
            $sliceSec = min(0.2, $remainingMs / 1000.0);
            $errno    = 0;
            $errstr   = '';
            $client   = @stream_socket_accept($listenSock, $sliceSec, $errstr);
            if (is_resource($client)) {
                return $client;
            }

            // No connection in this slice. Check whether the child has
            // already died (= exec failed, worker.php parse error,
            // missing connect target). If so, surface a precise error
            // instead of waiting for the full timeout.
            $status = 0;
            $r = pcntl_waitpid($childPid, $status, WNOHANG);
            if ($r === $childPid) {
                $exit = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
                throw new SpawnException(sprintf(
                    "ForkedWorker: worker pid=%d exited (status=%d) before connecting (socket=%s)",
                    $childPid,
                    $exit,
                    $sockPath,
                ));
            }
        }
    }

    private static function killAndReap(int $pid): void
    {
        // We do not preserve the child's exit status here — this path
        // is reached only on spawn() failure, where the caller wants to
        // know the spawn failed, not why the (probably-still-starting)
        // worker died.
        @posix_kill($pid, SIGKILL);
        $deadline = self::nowMs() + 2000;
        while (self::nowMs() < $deadline) {
            $status = 0;
            $r = pcntl_waitpid($pid, $status, WNOHANG);
            if ($r === $pid || $r === -1) {
                return;
            }
            usleep(5000);
        }
        // Last resort: blocking reap. Should be near-instantaneous
        // since we already SIGKILL'd.
        $status = 0;
        @pcntl_waitpid($pid, $status);
    }

    private static function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
