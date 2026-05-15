<?php

declare(strict_types=1);

namespace phpverse;

use phpverse\Isolation\FatalTrap;
use phpverse\Isolation\OutputCapture;
use phpverse\Isolation\RequestScope;
use phpverse\Protocol\Constants;
use phpverse\Protocol\FcgiCodec;
use phpverse\Protocol\ProtocolException;
use phpverse\Protocol\Record;

/**
 * FCGI worker: reads one full request, runs the target PHP script
 * inside a per-request isolation envelope (RequestScope + OutputCapture
 * + FatalTrap), and emits the response. Loops while FCGI_KEEP_CONN is
 * set on the active request; otherwise closes after responding.
 */
final class Worker
{
    /** @var resource */
    private $conn;

    private int $workerId;

    private FatalTrap $fatalTrap;

    /**
     * @param resource             $conn already-connected stream to the parent
     * @param array<string,mixed>  $opts 'workerId' => int, 'prepend' => ?string
     */
    public function __construct($conn, array $opts = [])
    {
        if (!is_resource($conn)) {
            throw new \InvalidArgumentException('Worker requires a stream resource');
        }
        $this->conn     = $conn;
        $this->workerId = (int) ($opts['workerId'] ?? 0);

        // Install the shutdown / exception handler chain exactly once
        // for the worker's lifetime. Per-request arm/disarm just swaps
        // the active OutputCapture in and out.
        $this->fatalTrap = new FatalTrap();
        $this->fatalTrap->arm();

        $prepend = $opts['prepend'] ?? null;
        if ($prepend !== null && $prepend !== '') {
            require $prepend;
        }
    }

    public function loop(): void
    {
        while (!feof($this->conn)) {
            $beginReq = $this->readBeginRequest();
            if ($beginReq === null) {
                return;
            }

            $params = $this->readUntilEmpty(Constants::FCGI_PARAMS, $beginReq->requestId);
            $stdin  = $this->readUntilEmpty(Constants::FCGI_STDIN, $beginReq->requestId);

            $paramsKv = FcgiCodec::readParams($params);

            $this->handleRequest($beginReq, $paramsKv, $stdin);

            // BeginRequestBody layout: role (2 BE), flags (1), 5 reserved.
            $flags = strlen($beginReq->content) >= 3 ? ord($beginReq->content[2]) : 0;
            if (($flags & Constants::FCGI_KEEP_CONN) === 0) {
                @fclose($this->conn);
                return;
            }
        }
    }

    private function readBeginRequest(): ?Record
    {
        $rec = FcgiCodec::readRecord($this->conn);
        if ($rec === null) {
            return null;
        }
        if ($rec->type !== Constants::FCGI_BEGIN_REQUEST) {
            throw new ProtocolException(sprintf(
                'expected FCGI_BEGIN_REQUEST, got type %d',
                $rec->type,
            ));
        }
        return $rec;
    }

    private function readUntilEmpty(int $expectedType, int $requestId): string
    {
        $buf = '';
        while (true) {
            $rec = FcgiCodec::readRecord($this->conn);
            if ($rec === null) {
                throw new ProtocolException(sprintf(
                    'EOF before empty record terminating type %d',
                    $expectedType,
                ));
            }
            if ($rec->type !== $expectedType) {
                throw new ProtocolException(sprintf(
                    'expected type %d, got %d',
                    $expectedType,
                    $rec->type,
                ));
            }
            if ($rec->requestId !== $requestId) {
                throw new ProtocolException(sprintf(
                    'multiplexed requestId %d (expected %d) — not supported',
                    $rec->requestId,
                    $requestId,
                ));
            }
            if ($rec->content === '') {
                return $buf;
            }
            $buf .= $rec->content;
        }
    }

    /**
     * @param array<string,string> $params
     */
    private function handleRequest(Record $beginReq, array $params, string $stdin): void
    {
        $requestId = $beginReq->requestId;
        $role = strlen($beginReq->content) >= 2
            ? ((ord($beginReq->content[0]) << 8) | ord($beginReq->content[1]))
            : 0;

        if ($role !== Constants::FCGI_RESPONDER) {
            $this->writeUnknownRole($requestId);
            return;
        }

        $script = $params['SCRIPT_FILENAME'] ?? '';
        // Strip the `proxy:fcgi://host:port` prefix that Apache
        // mod_proxy_fcgi and BayServer's FcgWarpHandler prepend (mirrors
        // php-fpm's main/fastcgi.c which does the same). After stripping
        // we are left with the actual on-disk path.
        if (str_starts_with($script, 'proxy:fcgi:')) {
            $colon = strrpos($script, ':');
            if ($colon !== false) {
                $script = substr($script, $colon + 1);
            }
        }
        $oc = new OutputCapture($this->conn, $requestId);

        if ($script === '' || !is_file($script) || !is_readable($script)) {
            $oc->emitErrorResponse(
                500,
                sprintf("script not found or unreadable: %s\n", $script),
            );
            return;
        }

        // Worker-injected $_SERVER addition; merge into the params so it
        // shows up in $_SERVER without leaking the worker's real $_ENV.
        $params['PHPVERSE_WORKER_ID'] = (string) $this->workerId;

        $scope = new RequestScope($params, $stdin);

        $this->fatalTrap->setActive($oc);
        $oc->start();
        try {
            $scope->activate();
            // Run via a static closure so user PHP can't see $this/$scope/$oc.
            (static function (string $__phpverse_script): void {
                require $__phpverse_script;
            })($script);
            $oc->finish();
        } catch (\Throwable $e) {
            $trace = sprintf(
                "Uncaught %s: %s\n  in %s:%d\n%s\n",
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString(),
            );
            $oc->emitErrorResponse(500, "Internal Server Error\n", $trace);
        } finally {
            $scope->restore();
            $this->fatalTrap->setActive(null);
        }
    }

    private function writeUnknownRole(int $requestId): void
    {
        $body = pack('NCxxx', 0, Constants::FCGI_UNKNOWN_ROLE);
        FcgiCodec::writeRecord($this->conn, Constants::FCGI_END_REQUEST, $requestId, $body);
    }
}
