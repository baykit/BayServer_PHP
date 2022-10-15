<?php

namespace baykit\bayserver\docker\fcgi\command;


use baykit\bayserver\docker\fcgi\FcgType;
use baykit\bayserver\protocol\CommandHandler;


/**
 * FCGI spec
 *   http://www.mit.edu/~yandros/doc/specs/fcgi-spec.html
 *
 * StdErr command format
 *   raw data
 */
class CmdStdErr extends InOutCommandBase
{
    public function __construct(int $reqId)
    {
        parent::__construct(FcgType::STDERR, $reqId);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleStdErr($this);
    }

}