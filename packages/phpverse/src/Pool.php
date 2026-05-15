<?php

declare(strict_types=1);

namespace phpverse;

use phpverse\Log\Logger;

/**
 * Daemon-mode worker pool: forks N children that share the parent's
 * listen socket and each call stream_socket_accept() directly on it
 * (php-fpm-style "shared listen, kernel-balanced accept").
 *
 * The Pool only owns process bookkeeping (PID map, respawn-on-SIGCHLD,
 * graceful shutdown). The per-connection FCGI dialogue is delegated to
 * {@see Worker}, exactly the same class library-mode callers use.
 *
 * Lifecycle:
 *   $pool = new Pool($listenSock, $n, $workerOpts);
 *   $pool->start();                  // forks N children
 *   // parent supervisor loop:
 *   //   on SIGCHLD: $pool->reapDeadWorkers();
 *   //   on SIGTERM: $pool->shutdown();
 *   //   on SIGUSR1: $pool->gracefulReload();
 *   //   on SIGUSR2: $pool->reopenLogs();
 */
final class Pool
{
    /** @var resource */
    private $listenSocket;

    private int $workerCount;

    /** @var array<string,mixed> */
    private array $workerOpts;

    /** @var array<int,int> pid → workerId (only populated in the parent). */
    private array $pidToWorkerId = [];

    private bool $shuttingDown = false;

    private bool $inChild = false;

    /** Per-worker shutdown flag set by the child's signal handlers. */
    private bool $childShutdownFlag = false;

    /** Per-worker "retire after current request" flag (SIGUSR1). */
    private bool $childRetireFlag = false;

    /** Per-worker "reopen log file" flag (SIGUSR2). */
    private bool $childReopenLogFlag = false;

    private ?Logger $logger = null;

    /** Numeric uid/gid to drop to in each worker after fork; null = inherit. */
    private ?int $workerUid = null;

    private ?int $workerGid = null;

    /**
     * @param resource             $listenSocket already bound + listening
     * @param array<string,mixed>  $workerOpts   forwarded to Worker plus
     *   maxRequests:int (0 = unlimited), logger:?Logger,
     *   workerUid:?int, workerGid:?int
     */
    public function __construct(
        $listenSocket,
        int $workerCount,
        array $workerOpts = [],
    ) {
        if (!is_resource($listenSocket)) {
            throw new \InvalidArgumentException('Pool requires a stream resource');
        }
        if ($workerCount < 1) {
            throw new \InvalidArgumentException('workerCount must be >= 1');
        }
        $this->listenSocket = $listenSocket;
        $this->workerCount  = $workerCount;
        $this->workerOpts   = $workerOpts;
        $this->logger       = ($workerOpts['logger'] ?? null) instanceof Logger
            ? $workerOpts['logger']
            : null;
        $this->workerUid    = isset($workerOpts['workerUid']) ? (int) $workerOpts['workerUid'] : null;
        $this->workerGid    = isset($workerOpts['workerGid']) ? (int) $workerOpts['workerGid'] : null;
    }

    public function start(): void
    {
        for ($i = 0; $i < $this->workerCount; $i++) {
            $this->forkOne($i);
        }
    }

    /**
     * Set maxRequests for subsequent worker spawns. Workers already
     * alive keep their current counter; the new value applies to the
     * next-spawned worker (via reapDeadWorkers + gracefulReload).
     */
    public function setMaxRequests(int $n): void
    {
        $this->workerOpts['maxRequests'] = max(0, $n);
    }

    public function getMaxRequests(): int
    {
        return (int) ($this->workerOpts['maxRequests'] ?? 0);
    }

    /**
     * SIGUSR1 handler entry point on the parent: ask every live worker
     * to retire after its current request. The SIGCHLD handler will
     * respawn a fresh worker at the same slot, naturally cycling the
     * pool without dropping the listen socket.
     */
    public function gracefulReload(): void
    {
        if ($this->inChild) {
            return;
        }
        $this->log('info', sprintf('graceful reload: signalling %d workers', count($this->pidToWorkerId)));
        foreach (array_keys($this->pidToWorkerId) as $pid) {
            @posix_kill($pid, SIGUSR1);
        }
    }

    /**
     * SIGUSR2 fanout: tell each worker to reopen its log file too. The
     * supervisor's own logger is reopened by DaemonMode before calling
     * this method.
     */
    public function reopenLogs(): void
    {
        if ($this->inChild) {
            return;
        }
        foreach (array_keys($this->pidToWorkerId) as $pid) {
            @posix_kill($pid, SIGUSR2);
        }
    }

    /**
     * Fork a worker at the given slot index. Parent records pid; child
     * runs the accept loop and exits.
     *
     * SIGTERM/SIGINT/SIGCHLD are blocked across the fork+early-init
     * window: otherwise a TERM that lands on the freshly-forked child
     * BEFORE installChildSignalHandlers() runs would be handled by the
     * parent's DaemonMode handler (inherited by fork), which sets a
     * flag the worker doesn't poll — the child then enters its accept
     * loop with no awareness of the pending shutdown, and only exits
     * when Pool::shutdown's SIGKILL fallback fires 3s later.
     */
    private function forkOne(int $workerId): void
    {
        $blocked = [SIGTERM, SIGINT, SIGCHLD, SIGUSR1, SIGUSR2, SIGHUP, SIGQUIT];
        $oldSet  = [];
        pcntl_sigprocmask(SIG_BLOCK, $blocked, $oldSet);

        $pid = pcntl_fork();
        if ($pid === -1) {
            pcntl_sigprocmask(SIG_SETMASK, $oldSet);
            throw new \RuntimeException("Pool: pcntl_fork() failed for worker $workerId");
        }
        if ($pid === 0) {
            // ----- CHILD -----
            $this->pidToWorkerId = [];
            $this->inChild       = true;
            $this->childShutdownFlag = false;
            $this->childRetireFlag   = false;
            $this->childReopenLogFlag = false;

            // Logger inherited from parent: re-target to this worker.
            if ($this->logger instanceof Logger) {
                $this->logger->setWorkerId($workerId);
                // Re-open the file fd so a future logrotate is observed
                // independently per worker (otherwise all workers share
                // the parent's already-opened fd, and rotation in the
                // parent does not propagate).
                $this->logger->reopen();
            }

            $this->installChildSignalHandlers();
            // Unblock now that our own handlers are in place. Any
            // signal pending at this point (sent before unblock) will
            // fire immediately, dispatched through OUR handler.
            pcntl_sigprocmask(SIG_SETMASK, $oldSet);

            // Drop privileges immediately after the fork. Order: gid
            // first, then uid (after setuid we cannot change gid).
            if (!$this->dropPrivilegesInChild($workerId)) {
                exit(1);
            }

            try {
                $this->runWorker($workerId);
            } catch (\Throwable $e) {
                $this->log('error', sprintf(
                    'worker %d crashed: %s: %s',
                    $workerId,
                    $e::class,
                    $e->getMessage(),
                ), [
                    'trace' => $e->getTraceAsString(),
                ]);
                exit(1);
            }
            exit(0);
        }
        // ----- PARENT -----
        $this->pidToWorkerId[$pid] = $workerId;
        $this->log('info', "spawned worker $workerId pid=$pid");
        pcntl_sigprocmask(SIG_SETMASK, $oldSet);
    }

    /**
     * Apply --worker-gid / --worker-uid in the child. Returns false on
     * verifiable failure (caller should exit 1).
     *
     * setgid MUST happen before setuid: after setuid to non-root, the
     * process loses CAP_SETGID and posix_setgid will refuse the call.
     */
    private function dropPrivilegesInChild(int $workerId): bool
    {
        if ($this->workerGid !== null) {
            if (!@posix_setgid($this->workerGid)) {
                $err = posix_get_last_error();
                $msg = posix_strerror($err);
                $this->log('error', sprintf(
                    'worker %d: posix_setgid(%d) failed: %s',
                    $workerId,
                    $this->workerGid,
                    $msg,
                ));
                return false;
            }
        }
        if ($this->workerUid !== null) {
            if (!@posix_setuid($this->workerUid)) {
                $err = posix_get_last_error();
                $msg = posix_strerror($err);
                $this->log('error', sprintf(
                    'worker %d: posix_setuid(%d) failed: %s',
                    $workerId,
                    $this->workerUid,
                    $msg,
                ));
                return false;
            }
            if (posix_geteuid() !== $this->workerUid) {
                $this->log('error', sprintf(
                    'worker %d: posix_setuid(%d) reported success but euid=%d',
                    $workerId,
                    $this->workerUid,
                    posix_geteuid(),
                ));
                return false;
            }
            $this->log('debug', sprintf(
                'worker %d: dropped to uid=%d gid=%d',
                $workerId,
                posix_geteuid(),
                posix_getegid(),
            ));
        }
        return true;
    }

    /**
     * Child entry point. Polls the shared listen socket with a bounded
     * stream_select timeout, accept-and-runs-Worker on each connection.
     *
     * Termination:
     *   - SIGTERM / SIGINT     → handler sets flag, next select tick exits
     *   - SIGUSR1              → handler sets retire flag, exit after current request
     *   - maxRequests reached  → exit 0 (parent respawns)
     */
    private function runWorker(int $workerId): void
    {
        $maxRequests = (int) ($this->workerOpts['maxRequests'] ?? 0);
        $handled     = 0;

        $optsForWorker = $this->workerOpts;
        unset(
            $optsForWorker['maxRequests'],
            $optsForWorker['logger'],
            $optsForWorker['workerUid'],
            $optsForWorker['workerGid'],
        );
        $optsForWorker['workerId'] = $workerId;

        // Put the shared listen socket in non-blocking mode so the
        // accept() that follows a select-readable will either return a
        // client or fail immediately (= lost to a sibling worker). A
        // blocking accept here would wedge the worker if the kernel
        // wakes multiple workers for a single connection (the
        // "thundering herd" — one of them wins, the rest fall into
        // accept and block forever, missing SIGTERM).
        stream_set_blocking($this->listenSocket, false);

        while (true) {
            pcntl_signal_dispatch();
            if ($this->childShutdownFlag) {
                return;
            }
            if ($this->childReopenLogFlag) {
                $this->childReopenLogFlag = false;
                if ($this->logger instanceof Logger) {
                    $this->logger->reopen();
                }
            }
            // Pure-idle retire: if SIGUSR1 was set and no request is in
            // flight, exit immediately so the parent can respawn fresh.
            if ($this->childRetireFlag) {
                return;
            }

            // Bounded select instead of a blocking accept: PHP installs
            // signal handlers with SA_RESTART by default, so a blocking
            // accept interrupted by SIGTERM is silently retried by the
            // kernel — the userland handler fires (sets the flag), but
            // the worker never gets back to the top of the loop to
            // observe it. A 1-second select tick bounds shutdown
            // latency.
            $read   = [$this->listenSocket];
            $write  = null;
            $except = null;
            $n = @stream_select($read, $write, $except, 1, 0);
            if ($n === false || $n === 0) {
                continue;
            }

            $errstr = '';
            $client = @stream_socket_accept($this->listenSocket, 0, $errstr);
            if (!is_resource($client)) {
                if ($this->childShutdownFlag) {
                    return;
                }
                // Lost the accept race to a sibling worker (thundering
                // herd), or a transient failure (e.g. EMFILE). Loop
                // back and try again.
                continue;
            }
            // Worker::loop expects blocking I/O on the client stream.
            // The listen-socket non-blocking flag does NOT propagate
            // to accept'd sockets on Linux, but be defensive.
            stream_set_blocking($client, true);

            try {
                $worker = new Worker($client, $optsForWorker);
                $worker->loop();
            } catch (\Throwable $e) {
                $this->log('error', sprintf(
                    'worker %d request error: %s: %s',
                    $workerId,
                    $e::class,
                    $e->getMessage(),
                ));
            } finally {
                if (is_resource($client)) {
                    @fclose($client);
                }
            }

            $handled++;
            if ($maxRequests > 0 && $handled >= $maxRequests) {
                return;
            }
            if ($this->childRetireFlag) {
                // Graceful reload (SIGUSR1) requested mid-request:
                // finished it cleanly, now exit so the parent respawns.
                return;
            }
        }
    }

    private function installChildSignalHandlers(): void
    {
        // Async dispatch so the signal handler runs at the next opcode
        // boundary, regardless of which syscall PHP is sitting in. The
        // worker loop polls the resulting flag on every iteration.
        pcntl_async_signals(true);
        $term = function (int $signo): void {
            $this->childShutdownFlag = true;
        };
        pcntl_signal(SIGTERM, $term);
        pcntl_signal(SIGINT, $term);
        // SIGUSR1: graceful retire after current request.
        pcntl_signal(SIGUSR1, function (int $signo): void {
            $this->childRetireFlag = true;
        });
        // SIGUSR2: reopen log file.
        pcntl_signal(SIGUSR2, function (int $signo): void {
            $this->childReopenLogFlag = true;
        });
        // Children must ignore SIGCHLD; only the supervisor reaps.
        pcntl_signal(SIGCHLD, SIG_DFL);
        // Parent-only signals: explicitly ignore so children don't
        // inherit something surprising.
        pcntl_signal(SIGHUP, SIG_IGN);
        pcntl_signal(SIGQUIT, SIG_DFL);
    }

    /**
     * Non-blocking reap. Called from the supervisor when SIGCHLD is
     * pending. Respawns a fresh worker at the dead slot unless we're
     * already shutting down.
     */
    public function reapDeadWorkers(): void
    {
        if ($this->inChild) {
            return;
        }
        while (true) {
            $status = 0;
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
            if ($pid === 0 || $pid === -1) {
                return;
            }

            $workerId = $this->pidToWorkerId[$pid] ?? null;
            unset($this->pidToWorkerId[$pid]);

            $cause = $this->describeStatus($status);
            $idStr = $workerId === null ? '?' : (string) $workerId;
            $this->log('info', "reaped worker $idStr pid=$pid ($cause)");

            if (!$this->shuttingDown && $workerId !== null) {
                $this->forkOne($workerId);
            }
        }
    }

    /**
     * Graceful shutdown: signal workers, wait up to $timeoutMs total,
     * SIGKILL stragglers, reap everything.
     *
     * Workers exit within ~1s of SIGTERM (= one accept-loop select
     * tick). 3s is plenty of headroom while keeping daemon-shutdown
     * latency bounded for callers.
     */
    public function shutdown(int $signal = SIGTERM, int $timeoutMs = 3000): void
    {
        if ($this->inChild) {
            return;
        }
        $this->shuttingDown = true;

        foreach (array_keys($this->pidToWorkerId) as $pid) {
            @posix_kill($pid, $signal);
        }

        $deadline = $this->nowMs() + max(0, $timeoutMs);
        while (!empty($this->pidToWorkerId) && $this->nowMs() < $deadline) {
            $this->reapDeadWorkers();
            if (empty($this->pidToWorkerId)) {
                return;
            }
            usleep(20_000);
        }

        // Anyone left is wedged; SIGKILL + blocking reap.
        foreach (array_keys($this->pidToWorkerId) as $pid) {
            @posix_kill($pid, SIGKILL);
        }
        foreach (array_keys($this->pidToWorkerId) as $pid) {
            $status = 0;
            @pcntl_waitpid($pid, $status);
            unset($this->pidToWorkerId[$pid]);
        }
    }

    /**
     * Immediate shutdown: SIGKILL all workers, reap them, return fast.
     *
     * Skips the per-worker drain step (= unlike shutdown()). Used by
     * the SIGQUIT path in {@see \phpverse\Cli\DaemonMode}.
     */
    public function shutdownImmediate(): void
    {
        if ($this->inChild) {
            return;
        }
        $this->shuttingDown = true;
        foreach (array_keys($this->pidToWorkerId) as $pid) {
            @posix_kill($pid, SIGKILL);
        }
        foreach (array_keys($this->pidToWorkerId) as $pid) {
            $status = 0;
            @pcntl_waitpid($pid, $status);
            unset($this->pidToWorkerId[$pid]);
        }
    }

    /** @return array<int,int> live pid → workerId map. */
    public function workerPids(): array
    {
        return $this->pidToWorkerId;
    }

    public function isShuttingDown(): bool
    {
        return $this->shuttingDown;
    }

    private function describeStatus(int $status): string
    {
        if (pcntl_wifexited($status)) {
            return 'exit=' . pcntl_wexitstatus($status);
        }
        if (pcntl_wifsignaled($status)) {
            return 'signal=' . pcntl_wtermsig($status);
        }
        return "raw=$status";
    }

    private function log(string $level, string $msg, array $context = []): void
    {
        if ($this->logger instanceof Logger) {
            $this->logger->logByName($level, $msg, $context);
        }
    }

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
