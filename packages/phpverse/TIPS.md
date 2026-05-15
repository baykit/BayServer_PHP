> Languages: **English** | [日本語](TIPS.ja.md)

# PHPVerse Tips

Programming notes and deployment guidance. Unlike SPEC.md (= the
immutable contract) and MANUAL.md (= the CLI reference), this document
gives **recommendations** that may evolve. Pick what fits your case.

## 1. Deployment pattern matrix

PHPVerse can be deployed along **two independent axes**:

- **Supervision axis**: who runs the worker pool manager?
  - **Daemon mode (D)** — the `phpverse` binary supervises.
  - **Library mode (L)** — the host application supervises directly.
- **Partitioning axis**: how are workers grouped relative to clients?
  - **Per-client pool (P)** — each client (e.g. each BayServer agent)
    has its own private group of workers.
  - **Global pool (G)** — one pool shared across all clients.

This gives **4 main combinations**, plus degenerate single-worker
variants. Below, "client" means whatever logical unit owns a pool —
typically a BayServer grand agent, an Nginx worker process, or a custom
web-server thread.

### 1.1 Pattern matrix

```
                ┌─────────────────────────┬─────────────────────────┐
                │  Per-client pool (P)    │  Global pool (G)        │
                ├─────────────────────────┼─────────────────────────┤
  Daemon (D)    │  D-P                    │  D-G                    │
                │  N daemons,             │  1 daemon, M workers,   │
                │   each with workers=M   │   all clients connect   │
                │  → BayServer recipe a   │  → "php-fpm style"      │
                ├─────────────────────────┼─────────────────────────┤
  Library (L)   │  L-P                    │  L-G                    │
                │  each client forks      │  master forks workers,  │
                │   its own M workers     │   clients share via     │
                │  → BayServer recipe c   │   shared queue          │
                │                         │  → BayServer recipe b   │
                └─────────────────────────┴─────────────────────────┘
```

Degenerate variants (less common):

- **D-P with workers=1**: many tiny daemons, each one worker. Useful
  only if you want per-daemon configuration (different PHP version per
  worker, etc.).
- **L-G with one worker**: pointless (1 worker for whole server, no
  parallelism).

### 1.2 Choosing a pattern

| Question | Pattern |
|---|---|
| You already have an FCGI client (Nginx etc.) and want a php-fpm replacement? | **D-G** |
| You have multiple independent clients on the same host and want each to have its own private pool (isolation, separate respawn cycles)? | **D-P** |
| You are building a web server and want PHP execution fully embedded (no extra daemon process in the tree)? | **L-P** or **L-G** |
| You want lowest per-request latency / minimum process count? | **L-P** (no daemon middleman, lean process tree) |
| You want least implementation work per host language? | **D-P** (each host's PharosDocker = "subprocess spawn + FCGI client") |

## 2. BayServer recipes

BayServer with `GRAND_AGENTS=N` raises a specific question: how many
total PHP workers do you end up with, and who manages them? Three
recipes are reasonable; pick by the host-language portability vs
process-count trade-off.

For all three, assume **`GRAND_AGENTS=8`** and target **32 PHP workers
total** (4 per agent).

### Recipe a — Daemon per agent (D-P, recommended for portability)

Each agent spawns `phpverse --workers=4`. The PHPVerse daemon manages
the 4 workers; the agent talks FCGI to the daemon over a per-agent
Unix socket.

```
BayServer master
├─ agent 0  ──fork──→  phpverse-0 (daemon, --workers=4)
│                       ├─ worker 0-0 .. 0-3
├─ agent 1  ──fork──→  phpverse-1 (daemon, --workers=4)
│                       ├─ worker 1-0 .. 1-3
   ⋮
└─ agent 7  ──fork──→  phpverse-7 (daemon, --workers=4)
                        ├─ worker 7-0 .. 7-3
```

| | |
|---|---|
| BayServer-side processes (agents) | 8 |
| PHPVerse supervisor processes     | 8 |
| Worker processes                  | 32 |
| Total                              | 48 + master |
| LB granularity                    | per-connection (kernel-LB across `accept()`'ers on the daemon's shared listen socket) |
| Per-host Pharos docker complexity | **low** — just "spawn phpverse subprocess + speak FCGI" |

Pros:

- Worker pool management (spawn, supervise, respawn, drain) lives in
  one place — the PHPVerse code, written in PHP, shared by all hosts.
- Each per-host PharosDocker (Java / Ruby / Python / Go / Node) is a
  thin layer (~100 lines): launch the daemon subprocess, hold the
  FCGI socket, route tours through it. Existing FCGI client code is
  reused.
- Per-agent isolation: one agent's pool is independent of another's.

Cons:

- 8 supervisor processes adding ~80 MB RSS overhead. Negligible on
  modern servers.
- LB granularity is per-connection (kernel decides which worker takes
  a new client connection). To get per-request LB, the FCGI client
  must disable `FCGI_KEEP_CONN`, paying ~10–30 µs/request for the
  reconnect.

This is the recommended starting point for BayServer-based deployments.

### Recipe b — Global daemon (D-G)

All BayServer agents share one PHPVerse daemon with `--workers=32`.

```
BayServer master
├─ agent 0 ─┐
├─ agent 1 ─│
│  ...      ├─ all agents connect to /var/run/phpverse.sock
├─ agent 7 ─┘
└─ phpverse-global (daemon, --workers=32)
    ├─ worker 0 .. 31
```

| | |
|---|---|
| BayServer-side processes (agents) | 8 |
| PHPVerse supervisor processes     | 1 |
| Worker processes                  | 32 |
| Total                              | 41 + master |
| LB granularity                    | per-connection (kernel-LB, but the pool is shared so workers can be used by any agent) |
| Per-host Pharos docker complexity | low |

Pros:

- Smallest process count.
- Workers can be utilized by any agent (cross-agent load smoothing).

Cons:

- **No per-agent isolation**. One agent's burst can starve another
  agent's requests.
- BayServer's grand-agent model presumes per-agent independence;
  forcing a global resource breaks that assumption.
- Listen socket is a single contention point.

Use this only if the BayServer deployment is small enough that cross-
agent isolation doesn't matter.

### Recipe c — Library mode per agent (L-P)

Each BayServer agent **directly** forks 4 worker.php processes. No
PHPVerse daemon binary in the tree.

```
BayServer master
├─ agent 0
│   ├─ fork → worker.php 0-0 (FCGI over fd)
│   ├─ fork → worker.php 0-1
│   ├─ fork → worker.php 0-2
│   └─ fork → worker.php 0-3
   ⋮
└─ agent 7 ...
```

| | |
|---|---|
| BayServer-side processes (agents) | 8 |
| PHPVerse supervisor processes     | 0 |
| Worker processes                  | 32 |
| Total                              | 40 + master |
| LB granularity                    | per-request (agent picks worker for each tour) |
| Per-host Pharos docker complexity | **high** — agent must implement worker spawn / respawn / idle queue / max-requests |

Pros:

- Leanest process tree (no supervisor middleman).
- True per-request LB: agent can use idle-queue + pending-queue (the
  algorithm in §3 below) for fair dispatch.
- No extra FCGI socket per agent.

Cons:

- Each host language re-implements the worker pool manager. To avoid
  bug-fix-times-6-languages, ship a shared `baykit.phpverse.client.*`
  library per language (PHPVerse provides the reference PHP one;
  community / BayServer team writes Java, Ruby, etc.).

Pick this if you care about minimum process count or want fine-grained
per-request LB control, and you're willing to maintain per-host pool
manager code.

### Recipe comparison summary

| | Recipe a (D-P) | Recipe b (D-G) | Recipe c (L-P) |
|---|---|---|---|
| Process count | 48 | 41 | **40** |
| Per-agent isolation | ✓ | ❌ | ✓ |
| LB granularity | connection | connection | **request** |
| Pharos per-host implementation effort | **low** | low | high |
| Recommended for | first port, multi-language fanout | small all-in-one setups | latency-sensitive / advanced LB |

For a fresh BayServer-PHP port, **start with Recipe a**. Switch to
Recipe c later if profiling shows the daemon-middleman overhead is
meaningful for your workload (rarely the case).

## 3. Load-balancing strategies (library mode)

When the host application owns the pool (= recipe c above), it picks
which worker handles each request. Recommended algorithm:

```
struct WorkerPool:
    idle: FIFO queue of Worker
    busy: Set of Worker
    pending: bounded FIFO queue of Tour

on tour_arrived(tour):
    if not idle.empty():
        w = idle.pop_front()
        busy.add(w)
        w.dispatch(tour)
    elif pending.length() < pending_cap:
        pending.push_back(tour)
    else:
        tour.respond_503("All workers busy and queue full")

on worker_finished(w):
    busy.remove(w)
    if not pending.empty():
        tour = pending.pop_front()
        busy.add(w)
        w.dispatch(tour)
    else:
        idle.push_back(w)
```

Properties:

- FIFO idle queue gives natural round-robin under steady load.
- Pending queue absorbs short bursts.
- 503 backpressure on overload (better than indefinite latency growth).
- `pending_cap` should be tuned per workload; a default of 2× the
  worker count is a reasonable starting point.

Variants:

- **Least recently used** instead of FIFO: identical behavior in steady
  state; differs only after worker respawn (the fresh worker is "most
  recently created" but should be eligible immediately — both
  algorithms handle this fine if you push fresh workers to the back).
- **Weighted RR**: if some workers have larger `max-requests` quotas
  (e.g. heterogeneous PHP versions), bias the idle queue.
- **Session affinity**: if your application benefits from a tour
  series hitting the same worker (e.g. PHP-side per-worker caches),
  hash the session id to pick a worker. Disable `FCGI_KEEP_CONN` so
  the kernel doesn't override your decision.

## 4. FCGI_KEEP_CONN trade-offs

`FCGI_KEEP_CONN = 1` (the bit on `FCGI_BEGIN_REQUEST.flags`) tells the
worker to keep the connection alive after `FCGI_END_REQUEST` for the
next request. Almost all FCGI clients default to this.

| Setting | Effect | Use when |
|---|---|---|
| `KEEP_CONN = 1` (default) | Same client connection → same worker for all its requests. Less reconnect overhead, but request-level LB is "best effort". | Most web-server-to-pool scenarios. |
| `KEEP_CONN = 0` | Each request gets a fresh connection. The pool sees uniform per-request load distribution. | Library-mode hosts that want true per-request LB; small short-lived clients. |

The 10–30 µs reconnect cost is invisible for typical PHP workloads
(>1 ms script execution). For microbenchmarks (<200 µs) the difference
is visible.

## 5. Performance tuning

### 5.1 Worker count

| Workload | Recommended `--workers` |
|---|---|
| CPU-bound (templates, math) | physical core count × 1.0–1.5 |
| I/O-bound (DB, external APIs) | physical core count × 2–4 |
| Mixed CRUD typical | 4–16 per host |

Over-provisioning workers costs RAM. Each worker holds the PHP
process state (~30–80 MB depending on extensions + preload).

### 5.2 opcache

PHPVerse is opcache-agnostic. Configure opcache in your `php.ini` or
via `-d` flags. Recommended for production:

```ini
opcache.enable_cli = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0          ; reload via SIGUSR1 instead
```

If you set `validate_timestamps = 0` (faster), use SIGUSR1 to reload
workers after deploying new code.

### 5.3 max-requests per worker

Set `--max-requests N` to retire workers after N requests:

| Application type | Suggested N |
|---|---|
| Well-behaved (no global state leaks) | 0 (no retirement) |
| Average | 1000–10000 |
| Memory-leak-prone (legacy code, certain frameworks) | 100–500 |

Retirement costs one cold start per N requests; over-aggressive
retirement (small N) hurts throughput.

### 5.4 Listen backlog

`phpverse` sets the listen socket backlog to `SOMAXCONN`. If a flood
of new connections exceeds the backlog, the kernel refuses them
(client sees connect-refused). Tune `net.core.somaxconn` if you
expect bursts > 4096 simultaneous new connections.

## 6. Common pitfalls

### 6.1 `exit()` / `die()` kills the worker

PHP's `exit()` and `die()` terminate the process. PHPVerse cannot
intercept this; the worker exits and the parent respawns. The
in-flight request is lost. **Use `return` to exit a request, not
`exit()`.**

If you must run third-party code that calls `exit()`, accept that
each such call costs one worker respawn (~100–500 ms cold start).

### 6.2 Global state leaks

PHPVerse workers are long-lived. Variables declared at the top level
of a script with `global $foo` persist across requests. Singletons,
static class properties, and `define()`d constants persist. **User
code is responsible for per-request cleanup** unless wrapped in a
function scope.

To bound leaks, use `--max-requests`.

### 6.3 `chdir()` leaking

PHPVerse snapshots and restores the worker's CWD per request, but
user code that calls `chdir()` and then early-returns may leave the
CWD in an unexpected state. PHPVerse's `register_shutdown_function`
trap restores it; rely on that, but don't depend on CWD
intra-request if you've used `chdir()` from third-party code.

### 6.4 Session locks across workers

PHP's default file-based session handler locks the session file for
the duration of the request. With multiple workers, a slow handler
can block subsequent requests from the same session. Use
`session_write_close()` early, or switch to a non-locking handler
(Redis with locking disabled, memcached, etc.).

### 6.5 Worker stderr pollution

Worker direct-writes to STDERR become `FCGI_STDERR` records that
clients typically log. Extensions that write to stderr (e.g.
`xdebug` traces) will flood your client's logs. Disable such
extensions in production builds.

## 7. Migration from php-fpm

Most php-fpm deployments translate directly:

| php-fpm | PHPVerse |
|---|---|
| `pm = dynamic`, `pm.max_children = N` | `--workers N` (static, no dynamic scaling in v1) |
| `pm.max_requests = N` | `--max-requests N` |
| `request_terminate_timeout = 30s` | `--max-script-seconds 30` |
| `listen = /var/run/php-fpm.sock` | `--listen unix:///var/run/phpverse.sock` |
| `user = www-data`, `group = www-data` | `--worker-uid www-data --worker-gid www-data` |
| `php_admin_value[opcache.*] = ...` | Set in `php.ini` (PHPVerse is opcache-agnostic) |
| `slowlog = ...` | Not supported in v1 (planned) |
| `pm.status_path = ...` | Not supported in v1 (planned) |

If you rely on php-fpm dynamic scaling, slowlog, or status page,
stay with php-fpm for now and revisit PHPVerse after those features
land.

## 8. Library-mode integration recipes

PHP host (any web server written in PHP) uses
`phpverse\Embed\ForkedWorker`. See [MANUAL.md §4](MANUAL.md) for the
API.

Non-PHP hosts pull in a per-language client library:

| Host language | Client library |
|---|---|
| Java   | `baykit.phpverse.client` (Maven artifact `io.baykit:phpverse-client`) |
| Ruby   | `baykit.phpverse.client` (gem `baykit-phpverse-client`) |
| Python | `baykit.phpverse.client` (PyPI `baykit-phpverse-client`) |
| Go     | `baykit.phpverse.client` (Go module `github.com/baykit/phpverse-client-go`) |
| Node   | `baykit.phpverse.client` (npm `@baykit/phpverse-client`) |
| Rust   | `baykit_phpverse_client` (crate `baykit-phpverse-client`) |

Each client library provides:

- A `Pool` / `ForkedWorker` equivalent that wraps fork + IPC.
- A FCGI codec (encoding requests + decoding responses).
- Integration helpers for the host's event loop (NIO Selector,
  goroutines, async/await, etc.).

These libraries are NOT part of PHPVerse itself; they live in their
own repos and depend only on the SPEC's wire protocol.
