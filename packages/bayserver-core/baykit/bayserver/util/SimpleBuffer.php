<?php
namespace baykit\bayserver\util;

class SimpleBuffer implements Reusable
{
    const INITIAL_BUFFER_SIZE = 32768;

    public $capacity;
    public $buf;
    public $len = 0;

    public function __construct($init=self::INITIAL_BUFFER_SIZE)
    {
        $this->capacity = $init;
        $this->buf = StringUtil::allocate($init);
    }

    //////////////////////////////////////////////////////////////////
    /// Implements Reusable
    //////////////////////////////////////////////////////////////////

    public function reset() : void
    {
        # clear for security raeson
        for($i = 0; $i < $this->len; $i++) {
            $this->buf[$i] = chr(0);
        }
        $this->len = 0;
    }


    ////////////////////////////////////////////////////////////////////////////////
    // Other methods
    ////////////////////////////////////////////////////////////////////////////////

    public function bytes() : string
    {
        return $this->buf;
    }

    public function putByte(string $b) : void
    {
        $this->put($b, 0, 1);
    }

    public function put(string $buf, int $pos = 0, int $len = null) : void
    {
        if ($len === null)
            $len = strlen($buf);

        while ($this->len + $len > strlen($this->buf))
            $this->extendBuf();

        for($i = 0; $i < $len; $i++) {
            $this->buf[$this->len + $i] = $buf[$pos + $i];
        }

        $this->len += $len;
    }

    public function extendBuf() : void
    {
        $new_buf = StringUtil::allocate(strlen($this->buf) * 2);
        for($i = 0; $i < $this->len; $i++) {
            $new_buf[$i] = $this->buf[$i];
        }
        $this->buf = $new_buf;
    }
}