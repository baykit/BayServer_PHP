<?php

namespace baykit\bayserver\docker\ajp\command;

use baykit\bayserver\docker\ajp\AjpCommand;
use baykit\bayserver\docker\ajp\AjpType;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;


/**
 * End response body format
 *
 * AJP13_END_RESPONSE :=
 *   prefix_code       5
 *   reuse             (boolean)
 */
class CmdEndResponse extends AjpCommand
{
    public $reuse;

    public function __construct()
    {
        parent::__construct(AjpType::END_RESPONSE, false);
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newAjpDataAccessor();
        $acc->putByte($this->type);
        $acc->putByte($this->reuse ? 1 : 0);

        // must be called from last line
        parent::pack($pkt);
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);

        $acc = $pkt->newAjpDataAccessor();
        $acc->getByte(); // prefix code
        $this->reuse = $acc->getByte() != 0;
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleEndResponse($this);
    }
}