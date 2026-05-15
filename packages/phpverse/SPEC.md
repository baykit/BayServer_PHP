> Languages: **English** | [日本語](SPEC.ja.md)

# PHPVerse Specification

Status: **draft v1**. This document is the canonical specification of
PHPVerse for the implementing agent.

## 1. Scope

PHPVerse is a PHP worker-pool library and standalone daemon that:

1. Manages a pool of long-lived child processes (workers), each of which
   hosts a PHP interpreter.
2. Speaks FastCGI 1.1 on the wire between client (parent / web server)
   and workers.
3. Provides a per-request isolation contract so user PHP scripts see a
   clean environment regardless of the worker's history.

This document covers: architecture, wire protocol profile, isolation
contract, worker lifecycle, CLI / configuration, and the implementation
TODO list.

### 1.1 Naming conventions

| Identifier | Value | Where it appears |
|---|---|---|
| PHP namespace | `phpverse\` (no `baykit\` prefix) | `src/Worker.php` → `namespace phpverse;`, `src/Protocol/FcgiCodec.php` → `namespace phpverse\Protocol;`, etc. |
| Composer package name | `baykit/phpverse` | `composer.json` `"name"` field |
| Composer autoload PSR-4 mapping | `"phpverse\\": "src/"` | `composer.json` `"autoload"` field |
| GitHub repository | `baykit/PHPVerse` | <https://github.com/baykit/PHPVerse> |
| Source-package path for non-PHP client libraries | `baykit.phpverse.*` | e.g. Java client class `baykit.phpverse.client.PhpverseClient`, Python module `baykit.phpverse.client`, etc. Matches the existing baykit family naming. |

The PHP namespace deliberately does **not** include the `baykit\`
prefix: PHPVerse is its own product, and the PHP namespace is kept short
and product-named. Non-PHP client libraries use the longer
`baykit.phpverse.*` form because their host conventions (Java packages,
Python module paths, etc.) typically include an organization namespace.

## 2. Goals and non-goals

### Goals

- **Process-isolated workers**. A user PHP fatal error / segfault /
  `exit()` kills exactly one worker; other workers and the parent are
  unaffected.
- **Standard FCGI wire**. Any FCGI client works without modification.
- **Library + daemon**. Same codebase serves both modes:
  - Library mode: parent application calls `ForkedWorker::spawn()` and
    talks FCGI over an inherited Unix domain socket.
  - Daemon mode: `phpverse --workers N --listen ...` runs a php-fpm-like
    process that accepts FCGI from arbitrary clients.
- **Zero external dependencies** beyond a PHP CLI binary with the
  `pcntl`, `posix`, and `sockets` extensions.
- **Documented per-request reset contract**. Users can rely on what is
  cleared and what persists between requests within a worker.

### Non-goals (v1)

- HTTP/2-style stream multiplexing inside a single worker connection.
  PHPVerse always uses FCGI request_id = 1 and handles one request at
  a time per connection. Parallelism comes from worker count.
- Cooperative async (Fiber, ReactPHP, Swoole). Workers run user PHP
  synchronously to completion.
- Hot reload of PHP code without worker restart. Users must use opcache
  with `opcache.validate_timestamps=1` or rotate workers via
  `maxRequestsPerWorker`.
- Replacing php-fpm in already-working production deployments.

## 3. Architecture

### 3.1 Process model

```
┌──────────────────────────────────────────────────────────┐
│ Parent (web server / agent / phpverse daemon)            │
│                                                            │
│   1. bind() Unix domain socket on /tmp/phpverse-{...}.sock │
│   2. listen()                                              │
│   3. for i in 0..N-1:                                      │
│        fork() + exec(worker.php)                           │
│        ↑ each child connects to parent's listen socket    │
└──────────────────────────────────────────────────────────┘
                       │
                       │ Unix domain socket per worker
                       │ (FCGI 1.1 wire format)
                       ▼
┌──────────────────────────────────────────────────────────┐
│ Worker (PHP CLI process)                                   │
│                                                            │
│   1. connect() to parent's socket using env var           │
│      PHPVERSE_SOCKET=/tmp/phpverse-{...}.sock              │
│   2. loop forever:                                         │
│        read FCGI_BEGIN_REQUEST                             │
│        read FCGI_PARAMS records until empty params record │
│        read FCGI_STDIN records until empty stdin record   │
│        run PHP script (require)                            │
│        emit FCGI_STDOUT records (response headers + body) │
│        emit FCGI_STDERR records if any                     │
│        emit FCGI_END_REQUEST                               │
└──────────────────────────────────────────────────────────┘
```

### 3.2 Connection ownership

- **Library mode**: parent owns the listen socket. After fork, the child
  inherits the listening fd via env var `PHPVERSE_SOCKET` (path) or
  `PHPVERSE_FD` (numeric fd if fd-passing is used). Child connects.
- **Daemon mode**: PHPVerse daemon owns the listen socket. External
  clients connect. Daemon then dispatches each accepted connection to
  an idle worker — typically by handing the accepted fd to the worker
  over a control socket (`SCM_RIGHTS` fd passing), or by having workers
  themselves call `accept()` on a shared listening socket (FCGI
  multiplexed-accept pattern).

For v1, recommend the **"shared listening socket, each worker
accepts"** pattern in daemon mode (this is exactly what php-fpm does).
In library mode, the simpler **"one socket per worker"** pattern works
fine.

### 3.3 Concurrency model

- One worker = one in-flight request. The connection between client
  and worker is held for the duration of the request and reused for
  subsequent requests (`FCGI_BEGIN_REQUEST.flags = FCGI_KEEP_CONN`).
- Parallelism is achieved by running multiple workers in parallel
  (configurable via `--workers N` or `Pool::start(n)`).
- Workers do not share state directly. Optional sharing via:
  - opcache shared memory (configure `opcache.file_cache` or
    `opcache.preload`)
  - APCu (each worker has its own APCu, no cross-worker sharing in v1)
  - filesystem / database (the user's choice)

### 3.4 Deployment patterns

PHPVerse supports both **daemon mode** (the `phpverse` binary
supervises) and **library mode** (the host application supervises
directly). Each mode admits per-agent / global partitioning variants.
The detailed pattern matrix, choosing rules, and host-specific
recommendations are not part of this contract; they live in
[TIPS.md](TIPS.md) so this SPEC stays focused on protocol + isolation
semantics.

What the SPEC does guarantee is that **the wire protocol (§4) and the
isolation contract (§5) are identical in both modes and every variant**.
A worker cannot tell whether its parent is the `phpverse` daemon or a
host application directly. A client cannot tell whether the FCGI
connection it holds is to a daemon-managed worker or a host-managed
one.

## 4. Wire protocol

PHPVerse uses **FastCGI 1.1** as specified in
<https://fastcgi-archives.github.io/FastCGI_Specification.html>.

### 4.1 Conformance level

- Role: **RESPONDER only**. Authorizer / Filter roles return
  `FCGI_UNKNOWN_ROLE` in `FCGI_END_REQUEST.protocolStatus`.
- Request multiplexing: not supported. The
  `FCGI_GET_VALUES_RESULT.FCGI_MPXS_CONNS` value is `"0"`. Clients must
  use one logical request per connection at a time. Pipelining
  (issuing the next `FCGI_BEGIN_REQUEST` before the previous
  `FCGI_END_REQUEST` is read) is rejected with `FCGI_CANT_MPX_CONN`.
- Connection: `FCGI_KEEP_CONN` is honored. Without it, the worker
  closes the connection after `FCGI_END_REQUEST`.
- Record types supported (worker emits and accepts as listed):

| Record type            | Code | Direction | Notes                                       |
|------------------------|-----:|-----------|---------------------------------------------|
| FCGI_BEGIN_REQUEST     | 1    | C → W     | role must be RESPONDER (1)                  |
| FCGI_ABORT_REQUEST     | 2    | C → W     | worker terminates current request           |
| FCGI_END_REQUEST       | 3    | W → C     | sent at end of response                     |
| FCGI_PARAMS            | 4    | C → W     | env vars; terminated by empty record        |
| FCGI_STDIN             | 5    | C → W     | request body; terminated by empty record    |
| FCGI_STDOUT            | 6    | W → C     | response headers + body                     |
| FCGI_STDERR            | 7    | W → C     | worker log / user error_log output          |
| FCGI_DATA              | 8    | -         | not used (FILTER role only)                 |
| FCGI_GET_VALUES        | 9    | C → W     | management record                           |
| FCGI_GET_VALUES_RESULT | 10   | W → C     | management record reply                     |
| FCGI_UNKNOWN_TYPE      | 11   | W → C     | sent in response to unknown management type |

### 4.2 Required FCGI_PARAMS keys

Workers MUST handle these standard CGI/1.1 keys (used to populate
`$_SERVER`):

```
REQUEST_METHOD       e.g. "GET", "POST"
REQUEST_URI          e.g. "/path?q=1"
QUERY_STRING         e.g. "q=1"
SCRIPT_NAME          e.g. "/index.php"
SCRIPT_FILENAME      absolute path; this is the file the worker requires
PATH_INFO            e.g. "/extra" (optional)
CONTENT_LENGTH       request body length (optional)
CONTENT_TYPE         request Content-Type (optional)
SERVER_NAME          host header value
SERVER_PORT          e.g. "8080"
SERVER_PROTOCOL      e.g. "HTTP/1.1"
SERVER_SOFTWARE      e.g. "PHPVerse/1.0"
REMOTE_ADDR          peer IP address
REMOTE_PORT          peer port (optional)
HTTPS                "on" if TLS, absent otherwise

HTTP_*               all request headers, with `-` → `_` and uppercased
                     e.g. "User-Agent" → HTTP_USER_AGENT
```

Any additional keys are passed through to `$_SERVER` verbatim.

### 4.3 FCGI_STDOUT format (response)

The first bytes of `FCGI_STDOUT` are HTTP-headers-like text:

```
Status: 200 OK\r\n
Content-Type: text/html; charset=utf-8\r\n
X-Custom: ...\r\n
\r\n
<html>...</html>
```

The empty line `\r\n\r\n` separates headers from body. Headers must
include `Status:` if the user set one via `http_response_code()` or
`header("HTTP/1.1 ...")`; otherwise the client assumes 200.

Body bytes may be split across multiple FCGI_STDOUT records.

### 4.4 FCGI_END_REQUEST

Sent after the response is complete (after the user's script returns
and any output buffers are flushed):

- `appStatus`: 0 normally; non-zero if the script exited with an error
  code (e.g. via `exit(1)`).
- `protocolStatus`: `FCGI_REQUEST_COMPLETE` (0) normally.

### 4.5 FCGI_GET_VALUES_RESULT

In response to `FCGI_GET_VALUES`, the worker reports:

| Key               | Value                                                  |
|-------------------|--------------------------------------------------------|
| FCGI_MAX_CONNS    | "1" (single connection per worker process)             |
| FCGI_MAX_REQS     | "1" (no multiplexing)                                  |
| FCGI_MPXS_CONNS   | "0"                                                    |
| PHPVERSE_VERSION  | implementation version string (PHPVerse extension)    |

## 5. Worker isolation contract

This section defines what user PHP can rely on regarding per-request
isolation.

### 5.1 Reset between requests

The following are **reset** to a clean state at the start of every
request:

- Superglobals: `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`, `$_REQUEST`,
  `$_FILES`, `$_ENV`, `$_SESSION` (if session started by user PHP).
  All are reassigned from the incoming FCGI_PARAMS / FCGI_STDIN.
- Output buffer: a fresh `ob_start()` is installed with a callback that
  writes to FCGI_STDOUT records. Any user-installed output buffers from
  a previous request are discarded.
- Sent headers: `header_remove()` is called. `headers_sent()` returns
  false at the start of each request.
- Error state: `error_get_last()` cleared.
- Current working directory: restored to the directory the worker was
  launched from. (Workers `chdir()` to the script's directory before
  `require`, then restore on exit.)
- Time limit: `set_time_limit(default)` is called.

### 5.2 NOT reset between requests

The following **persist** across requests within a worker (this is the
warm-process win):

- Opcache state (compiled bytecode).
- Loaded classes, interfaces, traits, functions (autoloader cache).
- Constants defined with `define()`.
- Static class properties and method-local statics.
- Global variables declared without `global` keyword (i.e. literally
  in the `$GLOBALS` table).
- Open file descriptors, database connections, etc., that the user
  PHP did not explicitly close.

**Implication**: user code that mutates static / global state is
responsible for its own per-request cleanup, or for accepting the
leak. To bound memory growth, configure `maxRequestsPerWorker` to
retire workers after N requests.

### 5.3 Fatal error handling

User-script fatal errors (E_ERROR / E_PARSE / E_CORE_ERROR /
E_COMPILE_ERROR) and uncaught throwables are trapped via:

1. `register_shutdown_function()` checks `error_get_last()` and, if
   it's fatal, writes a `Status: 500 Internal Server Error` response
   via FCGI_STDOUT and a stack trace via FCGI_STDERR.
2. `set_exception_handler()` catches uncaught exceptions, writes 500
   + trace.

After a trapped fatal, the worker **does not exit**; it resets state
and continues with the next request. (Exception: if the fatal is in
the framework code itself, not user code, the worker may be in an
inconsistent state — it should call `exit(1)` and let the parent
respawn it.)

### 5.4 `exit()` / `die()`

PHP's `exit()` and `die()` cannot be intercepted from PHP code. They
terminate the worker process. The parent process detects this via
`SIGCHLD` and respawns. The in-flight request is terminated and the
client sees a closed connection (or `FCGI_REQUEST_COMPLETE` with
non-zero appStatus if the worker emits it via `register_shutdown_function`).

Users should avoid `exit()` in normal flow. Use `return` from the
top-level script instead.

### 5.5 Output capture

User PHP output (`echo`, `print`, `printf`, `print_r`, etc.) is
captured by an `ob_start()` callback installed at the start of every
request:

```php
ob_start(function (string $chunk, int $phase) use ($conn) {
    if ($chunk !== '') {
        FcgiCodec::writeRecord($conn, FCGI_STDOUT, $requestId, $chunk);
    }
    return '';   // do not also write to the worker's actual stdout
}, /* chunkSize */ 8192);
```

The chunk size controls how often output is flushed. `flush()` from
user code triggers an immediate write.

Headers set by `header()` are also captured and emitted into the same
FCGI_STDOUT stream as the leading "Header: value\r\n" lines, followed
by `\r\n\r\n`, followed by the body bytes. This is handled by checking
`headers_list()` before the first body byte goes out.

### 5.6 error_log() and stderr

`error_log()` (default destination) and any direct writes to STDERR
are captured and emitted as FCGI_STDERR records. Clients typically
forward these to their own logging.

## 6. Worker lifecycle

### 6.1 Spawn

Parent process:

1. Create listen socket on `/tmp/phpverse-{pid}.sock` (or
   user-configured path).
2. For each requested worker index `i`:
   - `pcntl_fork()` (in PHP parent) or `posix_spawn` /
     `ProcessBuilder.start()` (in non-PHP parents).
   - Child sets `PHPVERSE_SOCKET=...` env var (the listen socket path)
     and `PHPVERSE_WORKER_ID=...` (numeric).
   - Child execs PHP CLI: `php /path/to/phpverse/worker.php` (or in PHP
     parent, the fork can just `require` worker.php directly without
     exec).
3. Each worker, on startup, opens a connection to the listen socket
   (`stream_socket_client("unix://$socketPath")`) and enters its FCGI
   request loop.

### 6.2 Warm-up

The very first request to a freshly-spawned worker may be slightly
slower due to opcache miss on `worker.php` and any preload PHP files.
Subsequent requests are warm.

If a `prependFile` is configured, the worker `require`s it once at
startup (not per request), so its top-level side effects are amortized.
If users need per-request prepend behavior, they should `require` it
from the script.

### 6.3 Drain and shutdown

When the parent decides to stop a worker (graceful shutdown, retire
after N requests, or full pool teardown):

1. Parent sends `FCGI_ABORT_REQUEST` on the worker's current
   connection (if a request is in flight) OR closes the connection
   after `FCGI_END_REQUEST` is received.
2. After the connection closes, the worker exits its main loop and
   calls `exit(0)`.
3. Parent reaps via `pcntl_waitpid()` / `Process.onExit()`.

Workers also respond to `SIGTERM` by finishing the current request
(if any) and then exiting cleanly.

### 6.4 Crash recovery

If a worker exits unexpectedly (uncaught fatal that bypassed the
trap, `exit()`, segfault, OOM kill):

- Parent's `SIGCHLD` handler observes the exit.
- Parent logs the event with the worker ID and exit code.
- Parent re-spawns a new worker at the same index.
- Any in-flight request on that worker is reported to the client as
  a 502 Bad Gateway (or the FCGI-equivalent: connection closed
  mid-response).

### 6.5 maxRequestsPerWorker

To bound memory growth from user code leaks, workers can be
configured to retire after handling N requests:

- After completing the Nth `FCGI_END_REQUEST`, the worker closes the
  connection and `exit(0)`s.
- Parent observes the exit and re-spawns a fresh worker.

`N = 0` (the default) means no retirement.

## 7. CLI surface and configuration

The `phpverse` binary CLI flags, environment variables, and config file
schema are operator-facing reference material and live in
[MANUAL.md](MANUAL.md). They are not part of the wire / isolation
contract that this SPEC governs. Spec compliance does NOT require
a specific CLI flag spelling, but it does require that the daemon mode
expose the equivalent capabilities (worker count, listen URI, max
requests per worker, prepend file, PHP binary path, privilege drop).

The library-mode embedding API (the PHP-side `phpverse\Embed\*`
classes and their non-PHP equivalents) is documented in
[MANUAL.md](MANUAL.md) under "Library mode" and in
[TIPS.md](TIPS.md) under "Library-mode integration recipes".

## 8. Implementation TODO list

For the implementing agent. Order matters; each step is testable.

### Phase 1: protocol codec

- [ ] `src/Protocol/Constants.php`: FCGI record type codes, role
      codes, status codes, version (1).
- [ ] `src/Protocol/FcgiCodec.php`:
  - `readRecord($stream): ?Record` — blocking read of one FCGI
    record. Returns null on EOF.
  - `writeRecord($stream, $type, $requestId, $content): void`
  - `readParams($paramsBytes): array<string,string>` — decode the
    name/value pair stream from one or more concatenated PARAMS
    records.
  - `writeParams(array $kv): string` — encode (used by clients).
- [ ] phpunit: round-trip a known FCGI byte stream → records → bytes.
      Include the FCGI/1.1 spec's example records as test fixtures.

### Phase 2: single-request worker

- [ ] `src/Worker.php`:
  - `__construct($conn, array $opts)`
  - `loop(): void` — read records, dispatch one full request to
    `handleRequest()`, write response records, repeat until peer
    closes connection.
  - `handleRequest($beginReq, array $params, string $stdin): void` —
    set up isolation, require the script, emit response.
- [ ] `worker.php` entry point: read `PHPVERSE_SOCKET` env, connect,
      construct Worker, run loop.
- [ ] Smoke test: launch worker.php with a test FCGI server stub
      that sends one BEGIN_REQUEST + PARAMS + STDIN; assert the
      response records match the expected output of a fixture
      `.php` file.

### Phase 3: isolation

- [ ] `src/Isolation/RequestScope.php`: snapshot/restore of
      superglobals; per-request reset.
- [ ] `src/Isolation/OutputCapture.php`: install ob_start callback
      that writes FCGI_STDOUT records; handle late header() calls.
- [ ] `src/Isolation/FatalTrap.php`: register_shutdown_function and
      set_exception_handler that emit 500 + trace.
- [ ] phpunit: end-to-end via Worker:
  - normal request returns 200 + body
  - script that throws → 500 + trace in STDERR
  - script with parse error → 500
  - script that calls header() then echoes → headers + body framed
    correctly
  - back-to-back requests on same worker do not see each other's
    $_GET / $_POST

### Phase 4: parent-fork helper

- [ ] `src/Embed/ForkedWorker.php`:
  - `static spawn(array $opts): self`
  - exposes `$worker->sock` and `$worker->pid`
  - `kill(int $sig = SIGTERM)`
- [ ] Demo script `examples/embedded.php` that uses ForkedWorker to
      run one request end-to-end.

### Phase 5: daemon mode

- [ ] `src/Pool.php`: manages N workers, listen socket, SIGCHLD
      respawn, max_requests retirement.
- [ ] `src/Cli/Main.php` + `src/Cli/DaemonMode.php`: argv parsing,
      flag handling, run Pool.
- [ ] `bin/phpverse` script.
- [ ] Integration test: start daemon with `--workers 2`, send 100
      FCGI requests via a test client, assert all 100 get correct
      responses and that exactly 2 worker PIDs were seen.

### Phase 6: production hardening

- [ ] Drop-privilege (`--worker-uid / --worker-gid`).
- [ ] Log rotation / format (--log-level, stdout/file output).
- [ ] PID file.
- [ ] Graceful reload (SIGUSR1 → drain + respawn all workers).
- [ ] TOML config file parsing.
- [ ] Documentation: docs/architecture.md, docs/protocol.md,
      docs/isolation.md, docs/cli.md (these can be expanded from
      this SPEC's sections).

## 9. Acceptance criteria

A v1.0 release requires:

1. All phases above complete with passing tests.
2. Pass a smoke matrix:
   - GET request with no body, plain text response
   - POST request with form body, parsed `$_POST`, plain response
   - POST request with raw body and `Content-Type: application/json`,
     readable via `php://input`
   - Streaming response (echo + flush in a loop)
   - 5 MB request body and 5 MB response body
   - Fatal error in user script → 500
   - `exit()` in user script → worker respawn → next request OK
3. Compatible with at least Nginx and Apache as FCGI clients
   (`fastcgi_pass unix:///var/run/phpverse.sock;` works).
4. Performance: within 2× of php-fpm on a simple `phpinfo()`-style
   benchmark, within 1.2× on a typical CRUD endpoint. (Exact thresholds
   to be re-evaluated after Phase 5.)

## 10. Open questions for the implementer

If you (the implementing agent) hit ambiguity in this spec:

1. **Fd passing in daemon mode**: shared-listen-and-accept-from-worker
   is simpler than SCM_RIGHTS fd passing. Use the shared-listen pattern
   unless you can demonstrate a concrete reason to prefer fd passing.

2. **Configuration of opcache across workers**: do not configure
   opcache from PHPVerse code. Users set `opcache.*` in their `php.ini`
   or via `-d` flags to the PHP CLI. PHPVerse is opcache-agnostic.

3. **Worker `exec()` vs PHP `require`**: in PHP parent mode, you can
   skip `exec()` and just `require worker.php` in the fork'd child. In
   non-PHP parent mode, exec is required. The CLI binary `phpverse
   --worker` is the canonical exec target.

4. **TCP listen support**: required by the spec but lower priority.
   Implement Unix-socket-only first; add TCP in Phase 5 or later.

5. **Windows support**: out of scope for v1. PHP CLI on Linux/macOS
   only.

6. **Backwards compatibility**: pre-1.0 means no compatibility
   guarantees. Once 1.0 ships, FCGI profile and CLI flags are stable;
   internal classes may move.

## 11. Reference

- FastCGI 1.1 specification:
  <https://fastcgi-archives.github.io/FastCGI_Specification.html>
- CGI/1.1 (RFC 3875): <https://datatracker.ietf.org/doc/html/rfc3875>
- PHP SAPI architecture (background reading):
  <https://www.php.net/manual/en/internals2.php>
