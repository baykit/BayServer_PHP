#!/bin/bash
#
# PhpVerseDocker smoke test.
#
# Starts BayServer with a minimal plan (test-fixtures/phpverse-smoke.plan)
# whose only club is *.php routed through PhpVerseDocker. Sends one curl
# to http://127.0.0.1:8080/test.php and asserts the response body
# contains "hello from phpverse". On success exits 0; on failure prints
# diagnostics and exits non-zero.
#
# This is NOT phpunit and not part of the automated test suite. Run it
# by hand after touching PhpVerseDocker / phpverse code.
#
# Prerequisites:
#   - composer dump-autoload has been run in bayserver-src/
#   - PHP CLI on PATH (or set PHP_BIN env var)
#   - phpverse bin at packages/phpverse/bin/phpverse is executable
#   - curl on PATH
#
# Tunables (env):
#   PORT=8080         HTTP listen port for the smoke run
#   PHP_BIN=php       PHP CLI binary
#   BSERV_TIMEOUT=10  seconds to wait for BayServer + phpverse readiness
#

set -eu

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
PLAN="packages/bayserver-docker-phpverse/test-fixtures/phpverse-smoke.plan"
PORT="${PORT:-8080}"
PHP_BIN="${PHP_BIN:-php}"
BSERV_TIMEOUT="${BSERV_TIMEOUT:-10}"

cd "$ROOT_DIR"

if [ ! -f vendor/autoload.php ]; then
    echo "smoke-test: vendor/autoload.php missing. Run 'composer dump-autoload' first." >&2
    exit 2
fi
if [ ! -x packages/phpverse/bin/phpverse ]; then
    chmod +x packages/phpverse/bin/phpverse || true
fi

LOG="$(mktemp -t phpverse-smoke.XXXXXX.log)"
echo "smoke-test: log file: $LOG"

cleanup() {
    if [ -n "${BSERV_PID:-}" ] && kill -0 "$BSERV_PID" 2>/dev/null; then
        kill -TERM "$BSERV_PID" 2>/dev/null || true
        for _ in $(seq 1 30); do
            kill -0 "$BSERV_PID" 2>/dev/null || break
            sleep 0.1
        done
        kill -KILL "$BSERV_PID" 2>/dev/null || true
    fi
}
trap cleanup EXIT

# Start BayServer in the background.
# BayServer CLI takes "-plan=PATH" (single arg, "=" form).
"$PHP_BIN" packages/bayserver/bin/bayserver_php \
    "-plan=$PLAN" >"$LOG" 2>&1 &
BSERV_PID=$!

# Wait for the port to listen.
ready=0
for _ in $(seq 1 "$((BSERV_TIMEOUT * 10))"); do
    if (echo >/dev/tcp/127.0.0.1/"$PORT") 2>/dev/null; then
        ready=1
        break
    fi
    if ! kill -0 "$BSERV_PID" 2>/dev/null; then
        break
    fi
    sleep 0.1
done

if [ "$ready" -ne 1 ]; then
    echo "smoke-test: BayServer did not become ready on port $PORT within ${BSERV_TIMEOUT}s." >&2
    echo "----- log -----" >&2
    sed -n '1,200p' "$LOG" >&2
    exit 1
fi

# Hit test.php; expect "hello from phpverse" in body.
RESP="$(curl -fsS "http://127.0.0.1:$PORT/test.php" || true)"
echo "smoke-test: response body:"
echo "$RESP"

if echo "$RESP" | grep -q "hello from phpverse"; then
    echo "smoke-test: PASS"
    exit 0
fi

echo "smoke-test: FAIL — body did not contain 'hello from phpverse'." >&2
echo "----- log -----" >&2
sed -n '1,200p' "$LOG" >&2
exit 1
