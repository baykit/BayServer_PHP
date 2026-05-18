<?php

namespace baykit\bayserver\protocol;

use baykit\bayserver\util\Reusable;

abstract class Command implements Reusable
{
    public $type;

    public function __construct(int $type)
    {
        $this->type = $type;
    }

    public abstract function unpack(Packet $pkt) : void;

    public abstract function pack(Packet $pkt) : void;

    // Call handler (visitor pattern)
    public abstract function handle(CommandHandler $handler) : int;
}