<?php

declare(strict_types=1);

namespace phpverse\Isolation;

use Throwable;

/**
 * Traps fatals, exits, and uncaught throwables in user PHP so the
 * client always sees a well-formed END_REQUEST (SPEC §5.3 / §5.4).
 *
 * PHP only has ONE shutdown chain and ONE exception handler per
 * process, so the registrations are installed exactly once per worker
 * lifetime (in arm()). Per-request arming is done by swapping the
 * active OutputCapture via setActive() — there is no way to "unregister"
 * a shutdown function, so we instead consult a nullable handle.
 */
final class FatalTrap
{
    private ?OutputCapture $active = null;

    private bool $installed = false;

    private const FATAL_MASK = E_ERROR
        | E_PARSE
        | E_CORE_ERROR
        | E_COMPILE_ERROR
        | E_USER_ERROR
        | E_RECOVERABLE_ERROR;

    public function arm(): void
    {
        if ($this->installed) {
            return;
        }
        register_shutdown_function([$this, 'onShutdown']);
        set_exception_handler([$this, 'onException']);
        $this->installed = true;
    }

    public function setActive(?OutputCapture $oc): void
    {
        $this->active = $oc;
    }

    public function onShutdown(): void
    {
        $oc = $this->active;
        if ($oc === null || $oc->isFinished()) {
            return;
        }
        // We only get here if user PHP terminated without Worker calling
        // setActive(null) first — either a fatal, an exit(), or a
        // throwable that escaped (the exception handler also fires, but
        // the shutdown handler runs after it).
        $err = error_get_last();
        if (is_array($err) && (((int) $err['type']) & self::FATAL_MASK) !== 0) {
            $trace = sprintf(
                "PHP Fatal error: %s in %s on line %d\n",
                (string) $err['message'],
                (string) $err['file'],
                (int) $err['line'],
            );
            $oc->emitErrorResponse(500, "Internal Server Error\n", $trace);
            return;
        }
        // exit()/die() with no fatal: emit a clean END_REQUEST so the
        // client doesn't see a bare closed connection (SPEC §5.4).
        $oc->finish();
    }

    public function onException(Throwable $e): void
    {
        $oc = $this->active;
        if ($oc === null || $oc->isFinished()) {
            return;
        }
        $trace = sprintf(
            "Uncaught %s: %s\n  in %s:%d\n%s\n",
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        );
        $oc->emitErrorResponse(500, "Internal Server Error\n", $trace);
    }
}
