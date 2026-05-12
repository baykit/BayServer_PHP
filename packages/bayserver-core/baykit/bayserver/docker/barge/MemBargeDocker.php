<?php

namespace baykit\bayserver\docker\barge;

use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\bcf\BcfKeyVal;
use baykit\bayserver\docker\Barge;
use baykit\bayserver\docker\Barge_Cargo;
use baykit\bayserver\docker\base\DockerBase;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\rudder\StreamRudder;
use baykit\bayserver\Sink;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\IOUtil;
use baykit\bayserver\util\StringUtil;

/**
 * In-memory cache implementation of Barge.
 *
 * Caches HTTP response bodies + headers keyed by request URI. On a hit,
 * the City docker bypasses the Club's arrive() entirely and serves
 * directly from the cargo bytes. Capacity is enforced as a total-byte
 * cap with LRU eviction; per-entry lifespan comes from
 * harbor.cargoLifespanSec().
 */
class MemBargeDocker extends DockerBase implements Barge
{
    public ?string $name = null;
    /** Capacity in bytes (default 32 MB) */
    public int $capacity = 32 * 1024 * 1024;
    public int $totalSize = 0;
    /** @var MemCargo[] path => cargo (insertion order = LRU age, oldest first) */
    public array $cargoMap = [];

    public function __toString() : string
    {
        return "MemBargeDocker[{$this->name}]";
    }

    //////////////////////////////////////////////////////
    // Implements Docker
    //////////////////////////////////////////////////////

    public function init(BcfElement $elm, ?Docker $parent) : void
    {
        parent::init($elm, $parent);
        $this->name = $elm->arg;
        if (StringUtil::isEmpty($this->name))
            $this->name = "*";
    }

    //////////////////////////////////////////////////////
    // Implements DockerBase
    //////////////////////////////////////////////////////

    public function initKeyVal(BcfKeyVal $kv) : bool
    {
        switch (strtolower($kv->key)) {
            default:
                return parent::initKeyVal($kv);

            case "capacity":
                $this->capacity = self::parseSize($kv->value);
                break;
        }
        return true;
    }

    //////////////////////////////////////////////////////
    // Implements Barge
    //////////////////////////////////////////////////////

    public function name() : string
    {
        return $this->name;
    }

    public function capacity() : int
    {
        return $this->capacity;
    }

    public function getCargo(Tour $tour) : array
    {
        $path = $tour->req->uri;
        $cgo = $this->cargoMap[$path] ?? null;
        $sourceRd = null;

        if ($cgo !== null && empty($cgo->waiters) && $cgo->expired()) {
            $this->totalSize -= $cgo->length();
            unset($this->cargoMap[$path]);
            $cgo = null;
        }

        if ($cgo === null) {
            $cgo = new MemCargo($this, $path);
            $this->cargoMap[$path] = $cgo;
            // First requester loads the file: disable the sendfile fast-path
            // (we want to also save the bytes into the cache as we send).
            $tour->res->directBoarding = false;
        }
        else {
            // LRU access-order: move the touched cargo to the end of the
            // map so it is the most-recently-used.
            unset($this->cargoMap[$path]);
            $this->cargoMap[$path] = $cgo;

            if ($cgo->status === MemCargo::LOADING) {
                // Cargo is loading: build a pipe so SpiderMultiplexer can
                // wake the waiter when the loader calls endSave().
                BayLog::debug("%s Cannot start tour (file reading)", $tour);
                try {
                    $pipes = IOUtil::openLocalPipe();
                    stream_set_blocking($pipes[0], false);
                    $sourceRd = new StreamRudder($pipes[0]);
                    $waitRd = new StreamRudder($pipes[1]);
                }
                catch (IOException $e) {
                    throw new Sink("Cannot create pipe: ". $e->getMessage());
                }
                $cgo->addWaiter($waitRd);
            }
            else {
                $tour->res->directBoarding = false;
            }
        }
        $cgo->access();
        return [$cgo, $sourceRd];
    }

    //////////////////////////////////////////////////////
    // Package-private (called by MemCargo)
    //////////////////////////////////////////////////////

    public function addTotal(int $len) : void
    {
        $this->totalSize += $len;
        BayLog::trace("%s addTotal=%d", $this, $this->totalSize);
        // Evict from the front (oldest) until total fits in capacity.
        foreach ($this->cargoMap as $key => $cgo) {
            if ($this->totalSize <= $this->capacity)
                break;
            if (empty($cgo->waiters)) {
                BayLog::trace("%s Evict cargo: %s len=%d total=%d", $this, $key, $cgo->length(), $this->totalSize);
                $this->totalSize -= $cgo->length();
                unset($this->cargoMap[$key]);
            }
            else {
                BayLog::trace("%s Skip cargo (has waiters): %s", $this, $key);
            }
        }
    }

    //////////////////////////////////////////////////////
    // Static helpers
    //////////////////////////////////////////////////////

    /** Parse "32m" / "1024k" / "512" (bytes) — mirrors upstream's StringUtil.parseSize */
    private static function parseSize(string $s) : int
    {
        $s = trim($s);
        if ($s === "") return 0;
        $last = strtolower($s[strlen($s) - 1]);
        $mult = 1;
        if ($last === 'k') $mult = 1024;
        else if ($last === 'm') $mult = 1024 * 1024;
        else if ($last === 'g') $mult = 1024 * 1024 * 1024;
        if ($mult !== 1)
            $s = substr($s, 0, -1);
        return intval($s) * $mult;
    }
}


/**
 * One cached response (inner class of MemBargeDocker upstream; PHP keeps
 * it in the same file).
 */
class MemCargo implements Barge_Cargo
{
    const LOADING = 1;
    const LOADED = 2;
    const EXCEEDED = 3;

    private MemBargeDocker $docker;
    public string $path;
    public int $length = 0;
    public int $status = self::LOADING;
    public string $buf = "";
    public Headers $headers;
    public int $lastAccessedTimeSec = 0;
    /** @var Rudder[] */
    public array $waiters = [];

    public function __construct(MemBargeDocker $docker, string $path)
    {
        $this->docker = $docker;
        $this->path = $path;
        $this->headers = new Headers();
    }

    public function __toString() : string
    {
        return "MemCargo[{$this->path}]";
    }

    public function path() : string
    {
        return $this->path;
    }

    public function headers() : Headers
    {
        return $this->headers;
    }

    public function content() : string
    {
        return $this->buf;
    }

    public function length() : int
    {
        return strlen($this->buf);
    }

    public function onBarge() : bool
    {
        return $this->status === self::LOADED;
    }

    public function exceeded() : bool
    {
        return $this->status === self::EXCEEDED;
    }

    public function saveHeaders(Headers $headers) : void
    {
        if ($this->onBarge())
            throw new \LogicException("already saved");
        if ($this->exceeded())
            return;
        $headers->copyTo($this->headers);
    }

    public function saveContent(string $bytes, int $offset, int $len) : void
    {
        if ($this->onBarge())
            throw new \LogicException("already saved");
        if ($this->exceeded())
            return;

        BayLog::debug("%s save content len=%d", $this, $len);
        $this->length += $len;

        if ($this->length > BayServer::$harbor->maxCargoSize()) {
            BayLog::debug("%s cargo exceeded: len=%d max=%d", $this, $this->length, BayServer::$harbor->maxCargoSize());
            $this->status = self::EXCEEDED;
            $this->buf = "";
            return;
        }
        $this->buf.= substr($bytes, $offset, $len);
    }

    public function endSave() : void
    {
        if ($this->onBarge())
            throw new \LogicException("already saved");
        if ($this->exceeded())
            return;

        BayLog::debug("%s end save", $this);
        $this->status = self::LOADED;
        $this->docker->addTotal($this->length);

        // Wake up everyone waiting on this cargo.
        foreach ($this->waiters as $rd) {
            try {
                BayLog::debug("%s notify waiter", $this);
                $rd->write("x");
            }
            catch (\Throwable $e) {
                BayLog::error_e($e);
            }
        }
    }

    public function releaseRudder(Rudder $rudder) : void
    {
        foreach ($this->waiters as $i => $w) {
            if ($w === $rudder) {
                array_splice($this->waiters, $i, 1);
                return;
            }
        }
    }

    public function access() : void
    {
        $this->lastAccessedTimeSec = time();
    }

    public function expired() : bool
    {
        return empty($this->waiters)
            && (time() - $this->lastAccessedTimeSec) > BayServer::$harbor->cargoLifespanSec();
    }

    public function addWaiter(Rudder $rd) : void
    {
        $this->waiters[] = $rd;
    }
}
