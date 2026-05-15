<?php

namespace baykit\bayserver\docker\phpverse;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\BayLog;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\bcf\BcfKeyVal;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\docker\fcgi\FcgWarpDocker;

/**
 * PhpVerseDocker — BayServer-side docker that spawns a {@link
 * phpverse} daemon as a child process and routes dynamic PHP
 * requests to it over FCGI.
 *
 * Implements Recipe α ("Daemon per agent" / D-P) from PHPVerse
 * TIPS.md §2: each BayServer grand agent spawns its own phpverse
 * daemon on a per-agent unix socket (the path is keyed on the
 * agent's PID). PhpVerseDocker extends FcgWarpDocker and reuses the
 * existing FCGI Warp dispatch / codec; the only new responsibilities
 * are
 *
 *   1. spawn the phpverse daemon on first agent start, and
 *   2. wire WarpBase's `host` / `port` so its arrive() path connects
 *      to the daemon's unix sock.
 *
 * Recipe β (single global daemon shared across all agents) is not
 * implemented in this docker because BayServer's `multiCore=yes`
 * agents are separate OS processes — a single supervisor would have
 * to be spawned pre-fork or external to BayServer to be reachable
 * from every agent without rebind races.
 */
class PhpVerseDocker extends FcgWarpDocker
{
    /** --workers passed to phpverse (total worker pool, shared across agents). */
    private int $workers = 4;

    /** --max-requests passed to phpverse (0 = unlimited). */
    private int $maxRequests = 0;

    /** --prepend file path (loaded once on worker startup). */
    private ?string $prependFile = null;

    /** Parent directory for the phpverse unix sock; defaults to sys_get_temp_dir(). */
    private ?string $socketDir = null;

    /** Path to the phpverse binary; defaults to the embedded packages/phpverse/bin/phpverse. */
    private ?string $phpverseBin = null;

    /** PHP CLI binary used to exec phpverse; defaults to PHP_BINARY. */
    private ?string $phpBinary = null;

    //////////////////////////////////////////////////////////////////
    // Per-agent daemon state (Recipe α).
    //
    // Static because multiple PhpVerseDocker instances within the same
    // grand-agent process (e.g. several <club> entries routing
    // different paths through PHP) share that agent's phpverse
    // daemon. Across agents these statics are independent — each
    // forked grand agent has its own static slot and so spawns its
    // own daemon on a PID-keyed sock path.
    //////////////////////////////////////////////////////////////////

    /** proc_open resource of the phpverse daemon, or null if not spawned. */
    private static $phpverseProcess = null;

    /** Path of the daemon's unix sock, or null if not spawned. */
    private static ?string $phpverseSockPath = null;

    /** True once the daemon has been spawned (post-bind) in this process. */
    private static bool $phpverseSpawned = false;

    /** True once a shutdown function has been registered (idempotent). */
    private static bool $shutdownHookRegistered = false;

    //////////////////////////////////////////////////////
    // Implements Docker
    //////////////////////////////////////////////////////

    public function init(BcfElement $elm, ?Docker $parent): void
    {
        // Parse plan keys via parent chain first so $this->workers et al. are populated.
        // We override initKeyVal() below for the phpverse-specific keys; FcgWarpDocker's
        // initKeyVal handles scriptBase / docRoot; WarpBase handles destCity / destPort /
        // destTown / maxShips / timeout.
        //
        // We intentionally don't call parent::init() yet because WarpBase::init() reads
        // $this->host and $this->port to decide unix-vs-tcp semantics, and we want to
        // populate those from our phpverse config first.

        // Resolve config keys (calls our initKeyVal()).
        foreach ($elm->contentList as $kv) {
            if ($kv instanceof BcfKeyVal) {
                $this->initKeyVal($kv);
            }
        }

        // Apply phpverse defaults.
        if ($this->socketDir === null) {
            $this->socketDir = sys_get_temp_dir();
        }
        if ($this->phpBinary === null) {
            $this->phpBinary = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
        }
        if ($this->phpverseBin === null) {
            // Default: packages/phpverse/bin/phpverse relative to this file:
            //   .../packages/bayserver-docker-phpverse/baykit/bayserver/docker/phpverse/PhpVerseDocker.php
            //                                       ↑ 5 levels up reaches packages/, then phpverse/bin/phpverse
            $this->phpverseBin = realpath(__DIR__ . '/../../../../..' . '/phpverse/bin/phpverse')
                ?: (__DIR__ . '/../../../../../phpverse/bin/phpverse');
        }

        // Compute an initial sock path so WarpBase::init() sees a non-empty
        // host. The path uses the *current* PID (= master at plan-parse time),
        // which is fine pre-fork; ensureSpawned() below will recompute the
        // path with the agent's own PID once we are running in the child
        // process, ensuring each grand agent binds a unique sock.
        if (self::$phpverseSockPath === null) {
            self::$phpverseSockPath = $this->socketDir . '/phpverse-bayserver-' . getmypid() . '.sock';
        }

        // Wire WarpBase's host/port to point at the daemon's unix sock.
        // WarpBase::init() recognizes the ":unix:" prefix, strips it, and sets
        // port=null so arrive() builds a "unix://" stream address.
        $this->host = ':unix:' . self::$phpverseSockPath;
        $this->port = -1;

        // Now let the parent chain finalize (WarpBase will register the
        // standard per-agent ship-store listener etc.).
        parent::init($elm, $parent);

        // Register our own per-agent listener for the daemon-spawn trigger.
        // The first agent.add() call drives spawnPhpverse(); subsequent ones
        // are no-ops thanks to self::$phpverseSpawned.
        GrandAgent::addLifecycleListener(new PhpVerseDocker_AgentListener($this));
    }

    //////////////////////////////////////////////////////
    // Implements DockerBase
    //////////////////////////////////////////////////////

    public function initKeyVal(BcfKeyVal $kv): bool
    {
        switch (strtolower($kv->key)) {
            case 'workers':
                $this->workers = max(1, intval($kv->value));
                break;
            case 'maxrequests':
                $this->maxRequests = max(0, intval($kv->value));
                break;
            case 'prependfile':
                $this->prependFile = $kv->value;
                break;
            case 'socketdir':
                $this->socketDir = $kv->value;
                break;
            case 'phpversebin':
                $this->phpverseBin = $kv->value;
                break;
            case 'php':
                $this->phpBinary = $kv->value;
                break;
            default:
                // Fall through to FcgWarpDocker (scriptBase / docRoot) and then
                // WarpBase (destCity / destPort / destTown / maxShips / timeout)
                // and DockerBase.
                return parent::initKeyVal($kv);
        }
        return true;
    }

    //////////////////////////////////////////////////////
    // Daemon spawn / shutdown
    //////////////////////////////////////////////////////

    /**
     * Called by the per-agent lifecycle listener on first agent add.
     * Idempotent — subsequent calls (additional agents, additional
     * PhpVerseDocker instances) short-circuit on self::$phpverseSpawned.
     */
    public function ensureSpawned(): void
    {
        if (self::$phpverseSpawned) {
            return;
        }
        // Recompute the sock path here, post-fork: getmypid() now returns
        // this grand agent's PID. init() ran in the master and seeded the
        // path with the master's PID, so without this update all agents
        // would race to bind() onto the same path. Also re-wire $this->host
        // before WarpBase opens any warp connections.
        //
        // WarpBase::init() consumed the ":unix:" prefix on first parse and
        // set $this->port = null to mark this as a unix-domain warp; the
        // post-fork rewrite must therefore use the bare path (no prefix).
        self::$phpverseSockPath = $this->socketDir . '/phpverse-bayserver-' . getmypid() . '.sock';
        $this->host = self::$phpverseSockPath;

        $this->spawnPhpverse();
    }

    private function spawnPhpverse(): void
    {
        if (self::$phpverseSpawned) {
            return;
        }

        if (self::$phpverseSockPath === null) {
            // Should not happen — init() always sets this — but guard anyway.
            self::$phpverseSockPath = ($this->socketDir ?? sys_get_temp_dir())
                . '/phpverse-bayserver-' . getmypid() . '.sock';
        }

        // Stale sock cleanup. phpverse will refuse to bind() onto an existing file.
        if (file_exists(self::$phpverseSockPath)) {
            @unlink(self::$phpverseSockPath);
        }

        if (!is_file($this->phpverseBin)) {
            throw new \RuntimeException(
                'PhpVerseDocker: phpverse binary not found at ' . $this->phpverseBin
            );
        }

        $cmd = [
            $this->phpBinary,
            $this->phpverseBin,
            '--workers',   (string) $this->workers,
            '--listen',    'unix://' . self::$phpverseSockPath,
            '--log-level', 'warn',
        ];
        if ($this->maxRequests > 0) {
            $cmd[] = '--max-requests';
            $cmd[] = (string) $this->maxRequests;
        }
        if ($this->prependFile !== null) {
            $cmd[] = '--prepend';
            $cmd[] = $this->prependFile;
        }

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        self::$phpverseProcess = proc_open($cmd, $desc, $pipes);
        if (!is_resource(self::$phpverseProcess)) {
            throw new \RuntimeException(
                'PhpVerseDocker: failed to spawn phpverse: ' . implode(' ', $cmd)
            );
        }
        // Close stdin (no input).
        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }
        // For v1, drain stdout/stderr by closing them. phpverse writes its own
        // logs via its --log-level path (defaults to its own stderr), and
        // since we inherit fds via proc_open pipes, closing here breaks the
        // pipe and phpverse falls back to write()-to-EPIPE — which it
        // tolerates because logs go to stderr fd directly. If you want to
        // surface phpverse warnings into BayLog, replace this with a
        // non-blocking reader registered with the net multiplexer.
        if (isset($pipes[1])) {
            fclose($pipes[1]);
        }
        if (isset($pipes[2])) {
            fclose($pipes[2]);
        }

        // Wait for phpverse to bind() its listen sock.
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            if (file_exists(self::$phpverseSockPath)) {
                break;
            }
            // Detect early death — proc_get_status's 'running' goes false
            // before we'd ever see the sock appear.
            $st = proc_get_status(self::$phpverseProcess);
            if (!$st['running']) {
                throw new \RuntimeException(
                    'PhpVerseDocker: phpverse exited early (exitcode='
                    . $st['exitcode'] . ') before binding sock '
                    . self::$phpverseSockPath
                );
            }
            usleep(50_000);
        }
        if (!file_exists(self::$phpverseSockPath)) {
            // Kill so we don't leak a wedged child.
            $st = proc_get_status(self::$phpverseProcess);
            if ($st['running']) {
                @posix_kill($st['pid'], SIGKILL);
            }
            @proc_close(self::$phpverseProcess);
            self::$phpverseProcess = null;
            throw new \RuntimeException(
                'PhpVerseDocker: phpverse did not bind '
                . self::$phpverseSockPath . ' within 5s'
            );
        }

        self::$phpverseSpawned = true;
        BayLog::info(
            'PhpVerseDocker: spawned phpverse (workers=%d, sock=%s)',
            $this->workers,
            self::$phpverseSockPath
        );

        if (!self::$shutdownHookRegistered) {
            register_shutdown_function([self::class, 'killPhpverse']);
            self::$shutdownHookRegistered = true;
        }
    }

    /**
     * Shutdown hook: SIGTERM the daemon, wait up to 3s, SIGKILL,
     * proc_close, and unlink the sock. Safe to call multiple times.
     */
    public static function killPhpverse(): void
    {
        if (is_resource(self::$phpverseProcess)) {
            $status = proc_get_status(self::$phpverseProcess);
            $pid = $status['pid'];
            if ($status['running']) {
                @posix_kill($pid, SIGTERM);
                $deadline = microtime(true) + 3.0;
                while (microtime(true) < $deadline) {
                    $s = proc_get_status(self::$phpverseProcess);
                    if (!$s['running']) {
                        break;
                    }
                    usleep(30_000);
                }
                $s = proc_get_status(self::$phpverseProcess);
                if ($s['running']) {
                    @posix_kill($pid, SIGKILL);
                }
            }
            @proc_close(self::$phpverseProcess);
            self::$phpverseProcess = null;
        }
        if (self::$phpverseSockPath !== null && file_exists(self::$phpverseSockPath)) {
            @unlink(self::$phpverseSockPath);
        }
        self::$phpverseSpawned = false;
    }
}
