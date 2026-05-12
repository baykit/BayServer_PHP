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
        if ($this->len > 0) {
            $this->buf = str_repeat(chr(0), strlen($this->buf));
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

        // Native substr_replace beats the per-char $buf[i] = $src[i] loop
        // by orders of magnitude (the per-char form was a hot-path drag
        // on every header-parse and small-body response).
        $slice = ($pos === 0 && $len === strlen($buf)) ? $buf : substr($buf, $pos, $len);
        $this->buf = substr_replace($this->buf, $slice, $this->len, $len);

        $this->len += $len;
    }

    public function extendBuf() : void
    {
        $newCap = strlen($this->buf) * 2;
        // Keep the existing $len bytes and pad the rest with zeros; the
        // previous per-char copy loop made buffer-extension a hot-path
        // O(N) PHP loop on every header that crossed a chunk boundary.
        $this->buf = substr($this->buf, 0, $this->len)
. str_repeat("\0", $newCap - $this->len);
    }
}