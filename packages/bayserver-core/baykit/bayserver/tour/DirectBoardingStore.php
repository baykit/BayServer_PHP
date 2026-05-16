<?php

namespace baykit\bayserver\tour;

use baykit\bayserver\BayServer;
use baykit\bayserver\util\DirectoryException;

/**
 * Tracks open file rudders so subsequent requests for the same path can
 * skip the file-open syscall.
 *
 * PHP cannot do a true sendfile(2) without blocking the agent's event
 * loop (stream_copy_to_stream is synchronous), so SpiderMultiplexer.
 * reqTransfer throws Sink and Direct Boarding never actually runs in
 * production. This class is kept so the configuration parameters
 * remain accepted by the parser; it is wired up but its outputs are
 * not consumed by a working transfer path.
 */
class DirectBoardingStore
{
    private static ?DirectBoardingStore $instance = null;

    /** @var DirectBoardingFileInfo[] path => info */
    private array $files = [];
    public int $maxCargos;
    public int $lifespanSec;
    public int $maxCargoSize;

    private function __construct(int $lifespanSec, int $maxCargos, int $maxCargoSize)
    {
        $this->lifespanSec = $lifespanSec;
        $this->maxCargos = $maxCargos;
        $this->maxCargoSize = $maxCargoSize;
    }

    public static function getFileInfo(string $path) : DirectBoardingFileInfo
    {
        return self::getStore()->get($path);
    }

    private function get(string $path) : DirectBoardingFileInfo
    {
        $info = $this->files[$path] ?? null;
        if ($info !== null && time() > $info->lastAccessTime + $this->lifespanSec) {
            $info->close();
            unset($this->files[$path]);
            $info = null;
        }

        if ($info === null) {
            if (is_dir($path)) {
                throw new DirectoryException("directory: ". $path);
            }

            $size = filesize($path);
            if ($size === false) $size = 0;

            if ($size > BayServer::$harbor->maxCargoSize()) {
                // Too big to cache the fd; remember the size only.
                $info = new DirectBoardingFileInfo($path, null, $size);
            }
            else {
                $fh = @fopen($path, "rb");
                if ($fh === false) {
                    throw new \RuntimeException("cannot open file: ". $path);
                }
                $info = new DirectBoardingFileInfo($path, $fh, $size);
            }

            // LRU access-order: insert at end. Evict from front when over max.
            $this->files[$path] = $info;
            while (count($this->files) > $this->maxCargos) {
                $oldKey = array_key_first($this->files);
                $this->files[$oldKey]->close();
                unset($this->files[$oldKey]);
            }
        }
        else {
            // Touch: move to end.
            unset($this->files[$path]);
            $this->files[$path] = $info;
        }

        $info->access();
        return $info;
    }

    private static function getStore() : DirectBoardingStore
    {
        if (self::$instance === null) {
            self::$instance = new self(BayServer::$harbor->cargoLifespanSec(),
                BayServer::$harbor->maxDirectBoardings(),
                BayServer::$harbor->maxCargoSize()
            );
        }
        return self::$instance;
    }
}


/**
 * Mirror of the DirectBoardingStore.FileInfo nested class.
 */
class DirectBoardingFileInfo
{
    public string $fileName;
    /** @var resource|null */
    public $fileHandle;
    public int $fileLength;
    public int $lastAccessTime;

    public function __construct(string $fileName, $fileHandle, int $len)
    {
        $this->fileName = $fileName;
        $this->fileHandle = $fileHandle;
        $this->fileLength = $len;
        $this->access();
    }

    public function access() : void
    {
        $this->lastAccessTime = time();
    }

    public function close() : void
    {
        if ($this->fileHandle !== null) {
            @fclose($this->fileHandle);
            $this->fileHandle = null;
        }
    }
}
