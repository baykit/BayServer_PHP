<?php

namespace baykit\bayserver\protocol;

use baykit\bayserver\BayLog;

class PacketPartAccessor
{
    private $packet;
    private $start;
    private $maxLen;
    public $pos;

    public function __construct(Packet $pkt, int $start, int $max_len)
    {
        $this->packet = $pkt;
        $this->start = $start;
        $this->maxLen = $max_len;
        $this->pos = 0;
    }

    public function putByte(int $b) : void
    {
        $this->putBytes(chr($b));
    }


    public function putBytes(?string $buf, int $ofs=0, int $len=-1) : void
    {
        if ($len == -1)
            $len = strlen($buf);

        if ($len > 0) {
            $this->checkWrite($len);
            for ($i = 0; $i < $len; $i++) {
                $this->packet->buf[$this->start + $this->pos + $i] = $buf[$ofs + $i];
            }
            $this->forward($len);
        }
    }

    public function putShort(int $val) : void
    {
        $buf = pack("n", $val);
        $this->putBytes($buf);
    }

    public function putInt(int $val) : void
    {
        $buf = pack("N", $val);
        $this->putBytes($buf);
    }

    public function putString(string $s) : void
    {
        $this->putBytes($s);
    }

    public function getByte() : int
    {
        $this->checkRead(1);
        $buf = $this->getBytes(1);
        $val = unpack("C", $buf)[1];
        if($val === null)
            BayLog::error("Unpack Error");
        return $val;
    }

    public function getBytes(int $length) : string
    {
        $this->checkRead($length);
        $val = substr($this->packet->buf, $this->start + $this->pos,  $length);
        $this->forward($length);
        return $val;
    }

    public function getShort() : int
    {
        $this->checkRead(2);
        $buf = $this->getBytes(2);
        if(strlen($buf) == 0)
            throw new \InvalidArgumentException();
        $val = unpack("n", $buf)[1];
        return $val;
    }

    public function getInt() : int
    {
        $this->checkRead(4);
        $buf = $this->getBytes(4);
        $val = unpack("N", $buf)[1];
        return $val;
    }

    public function checkRead(int $len) : void
    {
        $maxLen = ($this->maxLen >= 0) ? $this->maxLen : $this->packet->bufLen - $this->start;
        if ($this->pos + $len > $maxLen)
            throw new \Exception("Invalid read length");
    }

    public function checkWrite(int $len) : void
    {
        if ($this->maxLen > 0 && $this->pos + $len > $this->maxLen) {
            throw new \Exception("Buffer overflow");
        }
    }

    public function forward(int $len) : void
    {
        $this->pos += $len;
        if($this->start + $this->pos > $this->packet->bufLen)
            $this->packet->bufLen = $this->start + $this->pos;
    }
}
