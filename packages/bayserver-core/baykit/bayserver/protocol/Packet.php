<?php

namespace baykit\bayserver\protocol;

use baykit\bayserver\util\StringUtil;

class Packet
{
    const INITIAL_BUF_SIZE = 8192 * 4;

    public $type;
    public $buf;
    public $bufLen;
    public $headerLen;
    public $maxDataLen;

    public function __construct(int $type, int $headerLen, int $maxDataLen)
    {
        $this->type = $type;
        $this->headerLen = $headerLen;
        $this->maxDataLen = $maxDataLen;
        $this->buf = StringUtil::allocate(self::INITIAL_BUF_SIZE);
        $this->reset();
    }

    public function __toString() : string
    {
        $cls = get_class($this);
        return "Packet(class={$cls} type={$this->type})";
    }

    public function reset() : void
    {
        $this->buf = str_repeat(" ", $this->headerLen);
        $this->bufLen = $this->headerLen;
    }

    public function dataLen() : int
    {
        return $this->bufLen - $this->headerLen;
    }

    public function newHeaderAccessor() : PacketPartAccessor
    {
        return new PacketPartAccessor($this, 0, $this->headerLen);
    }

    public function newDataAccessor() : PacketPartAccessor
    {
        return new PacketPartAccessor($this, $this->headerLen, -1);
    }

}