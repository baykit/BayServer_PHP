<?php

namespace baykit\bayserver\docker\http\h2;

use baykit\bayserver\docker\http\h2\huffman\HTree;
use baykit\bayserver\protocol\Packet;
use baykit\bayserver\protocol\PacketPartAccessor;


/**
 * Http2 spec
 *   https://www.rfc-editor.org/rfc/rfc7540.txt
 *
 * Http2 Frame format
 * +-----------------------------------------------+
 * |                 Length (24)                   |
 * +---------------+---------------+---------------+
 * |   Type (8)    |   Flags (8)   |
 * +-+-+-----------+---------------+-------------------------------+
 * |R|                 Stream Identifier (31)                      |
 * +=+=============================================================+
 * |                   Frame Payload (0...)                      ...
 * +---------------------------------------------------------------+
 */
class H2HeaderAccessor extends PacketPartAccessor
{

    public function __construct(Packet $pkt, int $start, int $maxLen)
    {
        parent::__construct($pkt, $start, $maxLen);
    }

    public function putInt24(int $len) : void
    {
        $b1 = ($len >> 16) & 0xFF;
        $b2 = ($len >> 8) & 0xFF;
        $b3 = $len & 0xFF;
        $str = pack("C*", $b1, $b2, $b3);
        $this->putBytes($str);
    }
}


class H2DataAccessor extends PacketPartAccessor
{

    public function __construct(Packet $pkt, int $start, int $maxLen)
    {
        parent::__construct($pkt, $start, $maxLen);
    }

    public function getHPackInt(int $prefix, int &$head) : int
    {
        $maxVal = 0xFF >> (8 - $prefix);

        $firstByte = $this->getByte();
        $firstVal = $firstByte & $maxVal;
        $head = $firstByte >> $prefix;
        if($firstVal != $maxVal) {
            return $firstVal;
        }
        else {
            return $maxVal + $this->getHPackIntRest();
        }
    }

    public function getHPackIntRest() : int
    {
        $rest = 0;
        for($i = 0; ; $i++) {
            $data = $this->getByte();
            $cont = ($data & 0x80) != 0;
            $value = ($data & 0x7F);
            $rest = $rest + ($value << ($i * 7));
            if(!$cont)
                break;
        }
        return $rest;
    }

    public function getHPackString() : string
    {
        $isHuffman = -1;
        $len = $this->getHPackInt(7, $isHuffman);
        $data = $this->getBytes($len);
        if($isHuffman == 1) {
            return HTree::decode($data);
        }
        else {
            // ASCII
            return $data;
        }
    }

    public function putHPackInt(int $val, int $prefix, int $head) : void
    {
        $maxVal = 0xFF >> (8 - $prefix);
        $headVal = ($head  << $prefix) & 0xFF;
        if($val < $maxVal) {
            $this->putByte($val | $headVal);
        }
        else {
            $this->putByte($headVal | $maxVal);
            $this->putHPackIntRest($val - $maxVal);
        }
    }

    private function putHPackIntRest(int $val) : void
    {
        while(true) {
            $data = $val & 0x7F;
            $nextVal = $val >> 7;
            if($nextVal == 0) {
                // data end
                $this->putByte($data);
                break;
            }
            else {
                // data continues
                $this->putByte($data | 0x80);
                $val = $nextVal;
            }
        }
    }

    public function putHPackString(string $value, bool $haffman) : void
    {
        if($haffman) {
            throw new \InvalidArgumentException();
        }
        else {
            $this->putHPackInt(strlen($value), 7, 0);
            $this->putBytes($value);
        }
    }
}



class H2Packet extends Packet {

    const MAX_PAYLOAD_MAXLEN = 0x00FFFFFF; // = 2^24-1 = 16777215 = 16MB-1
    const DEFAULT_PAYLOAD_MAXLEN = 0x00004000; // = 2^14 = 16384 = 16KB
    const FRAME_HEADER_LEN = 9;
    
    const NO_ERROR = 0x0;
    const PROTOCOL_ERROR = 0x1;
    const INTERNAL_ERROR = 0x2;
    const FLOW_CONTROL_ERROR = 0x3;
    const SETTINGS_TIMEOUT = 0x4;
    const STREAM_CLOSED = 0x5;
    const FRAME_SIZE_ERROR = 0x6;
    const REFUSED_STREAM = 0x7;
    const CANCEL = 0x8;
    const COMPRESSION_ERROR = 0x9;
    const CONNECT_ERROR = 0xa;
    const ENHANCE_YOUR_CALM = 0xb;
    const INADEQUATE_SECURITY = 0xc;
    const HTTP_1_1_REQUIRED = 0xd;

    public $flags;  // H2Flags
    public $streamId = -1;

    public function __construct(int $type) {
        parent::__construct($type, self::FRAME_HEADER_LEN, self::DEFAULT_PAYLOAD_MAXLEN);
    }

    public function __toString() : string
    {
        return "H2Packet({$this->type}) hlen={$this->headerLen} dlen={$this->dataLen()} stm={$this->streamId} flg={$this->flags}";
    }

    public function reset() : void
    {
        $this->flags = new H2Flags();
        $this->streamId = -1;
        parent::reset();
    }

    public function packHeader() : void
    {
        $acc = $this->newH2HeaderAccessor();
        $acc->putInt24($this->dataLen());
        $acc->putByte($this->type);
        $acc->putByte($this->flags->flags);
        $acc->putInt(self::extractInt31($this->streamId));
    }

    public function newH2HeaderAccessor() : H2HeaderAccessor
    {
        return new H2HeaderAccessor($this, 0, $this->headerLen);
    }

    public function newH2DataAccessor() : H2DataAccessor
    {
        return new H2DataAccessor($this, $this->headerLen, -1);
    }

    public static function extractInt31(int $val) : int
    {
        return $val & 0x7FFFFFFF;
    }

    public static function extractFlag(int $val) : int
    {
        return (($val & 0x80000000) >> 31) & 1;
    }

    public static function consolidateFlagAndInt32(int $flag, int $val) : int
    {
        return ($flag & 1) << 31 | ($val & 0x7FFFFFFF);
    }

    public static function makeStreamDependency32(bool $excluded, int $dep) : int
    {
        return ($excluded ? 1 : 0) << 31 | self::extractInt31($dep);
    }
}