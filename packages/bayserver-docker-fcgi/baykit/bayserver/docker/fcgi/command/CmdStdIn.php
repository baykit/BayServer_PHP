<?php

namespace baykit\bayserver\docker\fcgi\command;


use baykit\bayserver\docker\fcgi\FcgType;
use baykit\bayserver\protocol\CommandHandler;


/**
 * FCGI spec
 * http://www.mit.edu/~yandros/doc/specs/fcgi-spec.html
 *
 * StdIn command format
 * raw data
 */
class CmdStdIn extends InOutCommandBase
{
    public function __construct()
    {
        parent::__construct(FcgType::STDIN);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleStdIn($this);
    }

}