> Languages: **English** | [日本語](MANUAL.ja.md)

# PHPVerse Manual

Operator-facing reference. CLI flags, configuration file, environment
variables, signals, logging.

For protocol / isolation semantics see [SPEC.md](SPEC.md). For
deployment patterns and host-specific recipes see [TIPS.md](TIPS.md).

## 1. CLI overview

The `phpverse` binary has three operating modes:

| Mode | Invocation | Used by |
|---|---|---|
| **Daemon mode** | `phpverse --workers N --listen ...` | system administrators (php-fpm-like deployment) |
| **Worker mode** (internal) | `phpverse --worker --socket ... --worker-id N` | the daemon parent when exec'ing fresh workers |
| **Library mode** | not via CLI; via `phpverse\Embed\*` PHP classes or `baykit.phpverse.client.*` libraries in other languages | host applications (BayServer, custom web servers) |

## 2. Daemon mode

### 2.1 Invocation

```
phpverse \
    --workers N \
    --listen URI \
    [--max-requests N] \
    [--max-script-seconds N] \
    [--prepend PATH] \
    [--php PATH] \
    [--worker-uid USER] \
    [--worker-gid GROUP] \
    [--pid-file PATH] \
    [--log-level LEVEL] \
    [--config PATH]
```

### 2.2 Flags

| Flag                       | Default                 | Description |
|----------------------------|-------------------------|-------------|
| `--workers N`              | required                | Number of worker processes to spawn. Sized like php-fpm's `pm.max_children`. |
| `--listen URI`             | required                | `unix:///path/to/sock` or `tcp://host:port`. |
| `--max-requests N`         | 0                       | Retire each worker after it has handled N requests; 0 means never retire. Bounds memory leaks. |
| `--max-script-seconds N`   | 30                      | Per-request hard kill threshold. Passed to PHP `set_time_limit`. |
| `--prepend PATH`           | -                       | Worker `require`s this once on startup (NOT per request). |
| `--php PATH`               | first `php` on `PATH`   | PHP CLI binary used to exec workers. |
| `--worker-uid USER`        | inherit                 | Drop privileges to this user (name or numeric uid) before spawning workers. Requires root at startup. |
| `--worker-gid GROUP`       | inherit                 | Drop privileges to this group. |
| `--pid-file PATH`          | -                       | Write parent PID to this file. |
| `--log-level LEVEL`        | `info`                  | One of `error`, `warn`, `info`, `debug`. |
| `--config PATH`            | -                       | Read defaults from a TOML config file. CLI flags override file values. |
| `--version`                | -                       | Print version and exit. |
| `--help`                   | -                       | Print help and exit. |

### 2.3 Listen URIs

- `unix:///absolute/path/to/sock` — Unix domain socket. Parent
  directory must exist and be writable by the user running `phpverse`.
  The socket file is created on start and unlinked on graceful stop.
- `tcp://host:port` — TCP socket. `host` may be `0.0.0.0` for all
  interfaces, `127.0.0.1` for loopback only, or a specific IP. The
  client (Nginx, etc.) connects to this address.

Unix socket is recommended for same-host deployments (lower overhead,
filesystem-level permissions); TCP for cross-host.

### 2.4 Configuration file

Optional TOML format. CLI flags override file values.

```toml
listen = "unix:///var/run/phpverse.sock"
workers = 8
max_requests = 1000
max_script_seconds = 30
prepend = "/etc/phpverse/prepend.php"
php = "/usr/bin/php"
log_level = "info"
pid_file = "/var/run/phpverse.pid"

[security]
worker_uid = "www-data"
worker_gid = "www-data"
```

Path lookup order for the config file:

1. `--config PATH` (explicit).
2. `$PHPVERSE_CONFIG` environment variable.
3. `/etc/phpverse/phpverse.conf`.
4. None (= all CLI defaults).

### 2.5 Environment variables (daemon)

| Var | Effect |
|---|---|
| `PHPVERSE_CONFIG`         | path to config file (overridden by `--config`) |
| `PHPVERSE_LOG_LEVEL`      | log level (overridden by `--log-level`) |
| `PHPVERSE_PID_FILE`       | pid file path (overridden by `--pid-file`) |

These are conveniences for systemd / container deployments; CLI flags
take precedence.

## 3. Worker mode (internal)

```
phpverse --worker --socket /tmp/phpverse-{pid}.sock --worker-id N
```

This is the form parent processes use when exec'ing a fresh worker. It
is **not** intended for end users; the daemon and the library-mode
helpers invoke it internally.

Worker mode is functionally equivalent to `php worker.php` with these
env vars set:

| Var | Description |
|---|---|
| `PHPVERSE_SOCKET`     | path to parent's listen socket (worker connects here) |
| `PHPVERSE_WORKER_ID`  | numeric worker id (for logging) |
| `PHPVERSE_PREPEND`    | optional path to a prepend file (loaded once on startup) |
| `PHPVERSE_MAX_REQUESTS` | retire after this many requests; absent / 0 = unlimited |

The `--worker` flag form exists so users can run a worker by hand for
debugging without setting env vars.

## 4. Library mode

For embedding inside a host application written in PHP:

```php
use phpverse\Embed\ForkedWorker;

$worker = ForkedWorker::spawn([
    'php'        => '/usr/bin/php',
    'workerPhp'  => __DIR__ . '/vendor/baykit/phpverse/worker.php',
    'prepend'    => null,
    // optional:
    'env'        => ['SOME_VAR' => 'value'],
    'maxRequests' => 0,
]);

// $worker->sock  : connected non-blocking stream resource
// $worker->pid   : child PID
// $worker->kill(SIGTERM);  // graceful stop
```

The host is then responsible for the FCGI conversation on
`$worker->sock`. The host's event loop should register this fd for
read events.

For non-PHP host languages, the equivalent helpers ship as separate
client libraries: `baykit.phpverse.client.*` (Java / Ruby / Python /
Go / Node / Rust). See [TIPS.md](TIPS.md) for host-specific recipes.

## 5. Signals

Daemon-mode parent process responds to:

| Signal | Behavior |
|---|---|
| `SIGTERM` / `SIGINT` | Graceful shutdown: stop accepting, drain in-flight requests, exit. |
| `SIGQUIT`            | Immediate shutdown: SIGKILL all workers, exit. |
| `SIGUSR1`            | Graceful reload: drain and respawn all workers (e.g. after deploying new PHP code and clearing opcache). |
| `SIGUSR2`            | Reopen log file (if `--log-level` writes to a file). |
| `SIGHUP`             | Reload config file and re-apply tunables that can be changed at runtime (`log_level`, `max_requests`). `--workers` and `--listen` require a full restart. |
| `SIGCHLD`            | Reap dead workers and respawn. |

Workers respond to:

| Signal | Behavior |
|---|---|
| `SIGTERM`            | Finish the current request (if any), then exit cleanly. |
| `SIGKILL`            | Die immediately (kernel-enforced). |
| `SIGUSR1`            | Set "retire on next idle" flag; worker exits after the next `FCGI_END_REQUEST`. |

## 6. Logging

PHPVerse writes operational logs to stderr by default. Log lines have
the form:

```
[YYYY-MM-DDTHH:MM:SS+ZZ:ZZ] [level] [worker-id?] message
```

Levels:

| Level | What it logs |
|---|---|
| `error` | worker crash, config error, fatal startup error |
| `warn`  | retry, slow worker, retired worker |
| `info`  | startup, shutdown, worker spawn / respawn |
| `debug` | per-request envelope details (verbose; not for production) |

User PHP output (echo, etc.) goes via FCGI to the client, NOT to the
PHPVerse log. User `error_log()` and direct stderr writes from PHP
become FCGI_STDERR records, which the client typically logs.

## 7. Exit codes

Daemon parent:

| Exit code | Meaning |
|---|---|
| 0 | graceful shutdown |
| 1 | runtime fatal (e.g. listen socket bind failed mid-operation) |
| 2 | config error (bad CLI flags, invalid config file) |
| 64 | usage error (--help, --version, or unrecognized flag without --help) — follows BSD sysexits |

## 8. Operational examples

### 8.1 Systemd unit

```ini
# /etc/systemd/system/phpverse.service
[Unit]
Description=PHPVerse FCGI worker pool
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/phpverse --config /etc/phpverse/phpverse.conf
ExecReload=/bin/kill -USR1 $MAINPID
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### 8.2 Reloading PHP code

After deploying new PHP files, reload workers so they pick up the new
opcache state:

```
systemctl reload phpverse        # sends SIGUSR1 → graceful worker respawn
```

### 8.3 Inspecting in-flight requests

PHPVerse v1 has no built-in status endpoint. Use standard Linux tools:

```
ss -lx | grep phpverse           # is the socket listening?
ps -ef | grep phpverse           # what worker PIDs are alive?
strace -p <worker-pid>           # what is one worker doing right now?
```

A `--status-endpoint` flag (= php-fpm `pm.status_path` equivalent)
may be added in a later minor release.
