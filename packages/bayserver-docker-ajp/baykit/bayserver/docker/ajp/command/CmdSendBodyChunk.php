<?php

namespace baykit\bayserver\docker\ajp\command;

use baykit\bayserver\docker\ajp\AjpCommand;
use baykit\bayserver\docker\ajp\AjpPacket;
use baykit\bayserver\docker\ajp\AjpType;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;
use baykit\bayserver\util\StringUtil;


/**
 * Send body chunk format
 *
 * AJP13_SEND_BODY_CHUNK :=
 * prefix_code   3
 * chunk_length  (integer)
 * chunk        *(byte)
 */
class CmdSendBodyChunk extends AjpCommand
{
    public $chunk = null;
    public $offset = 0;
    public $length = 0;

    const MAX_CHUNKLEN = AjpPacket::MAX_DATA_LEN - 4;

    public function __construct()
    {
        parent::__construct(AjpType::SEND_BODY_CHUNK, false);
    }

    public function init(string $buf, int $ofs, int $len) : void
    {
        $this->chunk = $buf;
        $this->offset = $ofs;
        $this->length = $len;
    }

    public function reset() : void
    {
        $this->chunk = null;
        $this->offset = 0;
        $this->length = 0;
    }

    public function pack(Packet $pkt): void
    {
        if($this->length > self::MAX_CHUNKLEN)
            throw new \InvalidArgumentException();

        $acc = $pkt->newAjpDataAccessor();
        $acc->putByte($this->type);
        $acc->putShort($this->length);
        $acc->putBytes($this->chunk, $this->offset, $this->length);
        $acc->putByte(0);   // maybe document bug

        // must be called from last line
        parent::pack($pkt);
    }

    public function unpack(Packet $pkt): void
    {
        $acc = $pkt->newAjpDataAccessor();
        $acc->getByte(); // code
        $this->length = $acc->getShort();
        if($this->chunk == null || $this->length > strlen($this->chunk))
            $this->chunk = StringUtil::allocate($this->length);
        $this->chunk = $acc->getBytes($this->length);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleSendBodyChunk($this);
    }
}