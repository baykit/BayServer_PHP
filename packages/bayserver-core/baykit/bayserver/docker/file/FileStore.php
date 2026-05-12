<?php

namespace baykit\bayserver\docker\file;

use baykit\bayserver\BayLog;

/**
 * In-memory cache for static file contents.
 *
 * Simplified for single-process PHP:
 *  - synchronous file load on miss (no WaitFileShip)
 *  - LRU eviction by access order using PHP associative array
 *    ordering (move-to-end on hit)
 *  - lifespanSec controls per-entry expiry from loadedTime
 *  - limitBytes caps total cached bytes
 */
class FileStore
{
    /** @var FileContent[] path => FileContent (insertion order = LRU age) */
    private array $contents = [];
    public int $limitBytes;
    private int $totalBytes = 0;
    private int $lifespanSec;

    public function __construct(int $lifespanSec, int $limitBytes)
    {
        $this->lifespanSec = $lifespanSec;
        $this->limitBytes = $limitBytes;
    }

    /**
     * Look up a file's content. On cache hit, returns the cached
     * FileContent. On cache miss, attempts to read+cache the file and
     * return the new FileContent. Returns null if the file does not
     * exist, is a directory, or is too large to cache.
     */
    public function get(string $path) : ?FileContent
    {
        if (isset($this->contents[$path])) {
            $fc = $this->contents[$path];
            if ($fc->loadedTime + $this->lifespanSec >= time()) {
                // Cache hit: move to the end of the order so this
                // entry is treated as most-recently-used.
                unset($this->contents[$path]);
                $this->contents[$path] = $fc;
                return $fc;
            }
            // Expired: drop and re-read.
            BayLog::debug("Remove expired content: %s", $path);
            $this->totalBytes -= $fc->length();
            unset($this->contents[$path]);
        }

        if (!is_file($path)) {
            return null;
        }
        $len = filesize($path);
        if ($len === false || $len > $this->limitBytes) {
            return null;
        }

        while ($this->totalBytes + $len > $this->limitBytes && !empty($this->contents)) {
            $oldKey = array_key_first($this->contents);
            $oldFc = $this->contents[$oldKey];
            BayLog::debug("Evict cached content: %s", $oldKey);
            $this->totalBytes -= $oldFc->length();
            unset($this->contents[$oldKey]);
        }
        if ($this->totalBytes + $len > $this->limitBytes) {
            // Even after eviction it does not fit; do not cache.
            return null;
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return null;
        }
        $fc = new FileContent($path, $bytes);
        $this->contents[$path] = $fc;
        $this->totalBytes += $fc->length();
        return $fc;
    }
}
