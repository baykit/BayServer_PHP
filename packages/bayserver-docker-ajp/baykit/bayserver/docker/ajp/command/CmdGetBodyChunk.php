<?php

namespace baykit\bayserver\docker\ajp\command;

use baykit\bayserver\docker\ajp\AjpCommand;
use baykit\bayserver\docker\ajp\AjpType;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;


/**
 * Get Body Chunk format
 *
 * AJP13_GET_BODY_CHUNK :=
 *   prefix_code       6
 *   requested_length  (integer)
 */
class CmdGetBodyChunk extends AjpCommand
{
    public $reqLen;

    public function __construct()
    {
        parent::__construct(AjpType::GET_BODY_CHUNK, false);
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newAjpDataAccessor();
        $acc->putByte($this->type);
        $acc->putShort($this->reqLen);

        // must be called from last line
        parent::pack($pkt);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleGetBodyChunk($this);
    }
}