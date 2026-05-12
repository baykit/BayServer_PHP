<?php

namespace baykit\bayserver\common;

use baykit\bayserver\rudder\Rudder;

/**
 * One pending write to a rudder. Holds either:
 *  - a buffered byte string ($buf, the normal write path), or
 *  - a file rudder + (offset, length) for Direct Boarding (the sendfile
 *    fast-path -- not implemented in PHP, so the multiplexer just throws
 *    Sink when it sees a WriteUnit with $file set).
 *
baykit.bayserver.common.WriteUnit (moved from
 * agent/multiplexer/ extended for Direct Boarding in
 * 24b17df).
 */
class WriteUnit {
    public ?string $buf;
    public ?Rudder $file;
    public ?string $adr;
    public $tag;
    public $callback;
    public int $offset;
    public int $length;
    public int $written = 0;

    public function __construct(?string $buf, ?string $adr, $tag, ?callable $callback,
                                ?Rudder $file = null, int $offset = 0, int $length = 0)
    {
        $this->buf = $buf;
        $this->adr = $adr;
        $this->tag = $tag;
        $this->callback = $callback;
        $this->file = $file;
        $this->offset = $offset;
        $this->length = $length;
    }

    public static function forFile(Rudder $file, int $offset, int $length, ?callable $callback) : self
    {
        return new self(null, null, null, $callback, $file, $offset, $length);
    }

    public function done(bool $bufferAvailable = true) : void
    {
        // Forward the multiplexer's post-consumption bufferAvailable
        // signal. Callbacks queued before that refactor
        // ignore the arg.
        if($this->callback !== null)
            ($this->callback)($bufferAvailable);
    }

    public function skipFormalities() : bool
    {
        return $this->file !== null;
    }

    public function position() : int
    {
        return $this->offset + $this->written;
    }

    public function remaining() : int
    {
        if ($this->skipFormalities())
            return $this->length - $this->written;
        return strlen($this->buf) - $this->written;
    }

    public function forward(int $len) : void
    {
        $this->written += $len;
    }

    public function hasRemaining() : bool
    {
        return $this->remaining() > 0;
    }
}
