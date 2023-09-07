<?php

namespace baykit\bayserver\docker\fcgi\command;


use baykit\bayserver\BayLog;
use baykit\bayserver\docker\fcgi\FcgCommand;
use baykit\bayserver\docker\fcgi\FcgType;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;
use baykit\bayserver\protocol\PacketPartAccessor;
use baykit\bayserver\util\StringUtil;


/**
 * FCGI spec
 *   http://www.mit.edu/~yandros/doc/specs/fcgi-spec.html
 *
 *
 * Params command format (Name-Value list)
 *
 *         typedef struct {
 *             unsigned char nameLengthB0;  // nameLengthB0  >> 7 == 0
 *             unsigned char valueLengthB0; // valueLengthB0 >> 7 == 0
 *             unsigned char nameData[nameLength];
 *             unsigned char valueData[valueLength];
 *         } FCGI_NameValuePair11;
 *
 *         typedef struct {
 *             unsigned char nameLengthB0;  // nameLengthB0  >> 7 == 0
 *             unsigned char valueLengthB3; // valueLengthB3 >> 7 == 1
 *             unsigned char valueLengthB2;
 *             unsigned char valueLengthB1;
 *             unsigned char valueLengthB0;
 *             unsigned char nameData[nameLength];
 *             unsigned char valueData[valueLength
 *                     ((B3 & 0x7f) << 24) + (B2 << 16) + (B1 << 8) + B0];
 *         } FCGI_NameValuePair14;
 *
 *         typedef struct {
 *             unsigned char nameLengthB3;  // nameLengthB3  >> 7 == 1
 *             unsigned char nameLengthB2;
 *             unsigned char nameLengthB1;
 *             unsigned char nameLengthB0;
 *             unsigned char valueLengthB0; // valueLengthB0 >> 7 == 0
 *             unsigned char nameData[nameLength
 *                     ((B3 & 0x7f) << 24) + (B2 << 16) + (B1 << 8) + B0];
 *             unsigned char valueData[valueLength];
 *         } FCGI_NameValuePair41;
 *
 *         typedef struct {
 *             unsigned char nameLengthB3;  // nameLengthB3  >> 7 == 1
 *             unsigned char nameLengthB2;
 *             unsigned char nameLengthB1;
 *             unsigned char nameLengthB0;
 *             unsigned char valueLengthB3; // valueLengthB3 >> 7 == 1
 *             unsigned char valueLengthB2;
 *             unsigned char valueLengthB1;
 *             unsigned char valueLengthB0;
 *             unsigned char nameData[nameLength
 *                     ((B3 & 0x7f) << 24) + (B2 << 16) + (B1 << 8) + B0];
 *             unsigned char valueData[valueLength
 *                     ((B3 & 0x7f) << 24) + (B2 << 16) + (B1 << 8) + B0];
 *         } FCGI_NameValuePair44;
 *
 */
class CmdParams extends FcgCommand
{
    public $params = [];

    public function __construct(int $reqId)
    {
        parent::__construct(FcgType::PARAMS, $reqId);
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);

        $acc = $pkt->newDataAccessor();
        while($acc->pos < $pkt->dataLen()) {
            $nameLen = $this->readLength($acc);
            $valueLen = $this->readLength($acc);
            $name = $acc->getBytes($nameLen);
            $value = $acc->getBytes($valueLen);

            BayLog::trace("Params: %s=%s", $name, $value);
            $this->addParam($name, $value);
        }
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newDataAccessor();
        foreach($this->params as $nv) {
            $this->writeLength(strlen($nv[0]), $acc);
            $this->writeLength(strlen($nv[1]), $acc);

            $acc->putBytes($nv[0]);
            $acc->putBytes($nv[1]);
        }


        // must be called from last line
        parent::pack($pkt);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleParams($this);
    }

    public function readLength(PacketPartAccessor $acc) : int
    {
        $len = $acc->getByte();
        if($len >> 7 == 0) {
            return $len;
        }
        else {
            $len2 = $acc->getByte();
            $len3 = $acc->getByte();
            $len4 = $acc->getByte();
            return (($len & 0x7f) << 24) | ($len2 << 16) | ($len3 << 8) | $len4;
        }
}

    public function writeLength(int $len, PacketPartAccessor $acc) : void
    {
        if($len  >> 7 == 0) {
            $acc->putByte($len);
        }
        else {
            $bytes = StringUtil::allocate(4);
            $bytes[0] = chr(($len >> 24 & 0xFF) | 0x80);
            $bytes[1] = chr($len >> 16 & 0xFF);
            $bytes[2] = chr($len3 = $len >> 8 & 0xFF);
            $bytes[3] = chr($len & 0xFF);
            $acc->putBytes($bytes);
        }
}

    public function addParam(string $name, string $value) : void
    {
        if($name == null)
            throw new \InvalidArgumentException();
        if($value == null)
            $value = "";
        $this->params[] = [$name, $value];
    }

}