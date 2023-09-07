<?php

namespace baykit\bayserver\docker\http\h2;

class H2Flags {

    const FLAGS_NONE = 0x0;
    const FLAGS_ACK = 0x1;
    const FLAGS_END_STREAM = 0x1;
    const FLAGS_END_HEADERS = 0x4;
    const FLAGS_PADDED = 0x8;
    const FLAGS_PRIORITY = 0x20;

    public $flags = self::FLAGS_NONE;

    public function __construct(int $flags = self::FLAGS_NONE) 
    {
        $this->flags = $flags;
    }

    public function __toString() : string
    {
        return dechex($this->flags);
    }

    public function getFlag(int $flag) : bool
    {
        return ($this->flags & $flag) != 0;
    }

    public function setFlag(int $flag, bool $val) : void
    {
        if($val)
            $this->flags |= $flag;
        else
            $this->flags &= ~$flag;
    }

    public function ack() : bool
    {
        return $this->getFlag(self::FLAGS_ACK);
    }

    public function setAck(bool $isAck) : void 
    {
        $this->setFlag(self::FLAGS_ACK, $isAck);
    }

    public function endStream() : bool
    {
        return $this->getFlag(self::FLAGS_END_STREAM);
    }

    public function setEndStream(bool $isEndStream) : void
    {
        $this->setFlag(self::FLAGS_END_STREAM, $isEndStream);
    }

    public function endHeaders() : bool
    {
        return $this->getFlag(self::FLAGS_END_HEADERS);
    }

    public function setEndHeaders(bool $isEndHeaders) : void
    {
        $this->setFlag(self::FLAGS_END_HEADERS, $isEndHeaders);
    }

    public function padded() : bool
    {
        return $this->getFlag(self::FLAGS_PADDED);
    }

    public function setPadded(bool $isPadded) : void
    {
        $this->setFlag(self::FLAGS_PADDED, $isPadded);
    }

    public function priority() : bool
    {
        return $this->getFlag(self::FLAGS_PRIORITY);
    }

    public function setPriority(bool $flag) : void
    {
        $this->setFlag(self::FLAGS_PRIORITY, $flag);
    }
}