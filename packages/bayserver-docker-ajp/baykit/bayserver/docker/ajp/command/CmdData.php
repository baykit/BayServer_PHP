<?php

namespace baykit\bayserver\docker\ajp\command;

use baykit\bayserver\docker\ajp\AjpCommand;
use baykit\bayserver\docker\ajp\AjpPacket;
use baykit\bayserver\docker\ajp\AjpType;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;


/**
 * Data command format
 *
 *   raw data
 */
class CmdData extends AjpCommand
{
    const LENGTH_SIZE = 2;
    const MAX_DATA_LEN = AjpPacket::MAX_DATA_LEN - self::LENGTH_SIZE;

    public $start;
    public $length;
    public $data;

    public function __construct(string $data, int $start = 0, int $len = 0)
    {
        parent::__construct(AjpType::DATA, true);
        $this->data = $data;
        $this->start = $start;
        $this->length = $len;
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);

        $acc = $pkt->newAjpDataAccessor();
        $this->length = $acc->getShort();
        $this->data = $pkt->buf;
        $this->start = $pkt->headerLen + 2;
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newAjpDataAccessor();
        $acc->putShort($this->length);
        $acc->putBytes($this->data, $this->start, $this->length);

        // must be called from last line
        parent::pack($pkt);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleData($this);
    }
}