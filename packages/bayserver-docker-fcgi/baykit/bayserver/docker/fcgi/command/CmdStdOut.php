<?php

namespace baykit\bayserver\docker\fcgi\command;


use baykit\bayserver\docker\fcgi\FcgType;
use baykit\bayserver\protocol\CommandHandler;


/**
 * FCGI spec
 *   http://www.mit.edu/~yandros/doc/specs/fcgi-spec.html
 *
 * StdOut command format
 *   raw data
 */
class CmdStdOut extends InOutCommandBase
{
    public function __construct(int $reqId, ?string $data=null, int $start=0, int $len=0)
    {
        parent::__construct(FcgType::STDOUT, $reqId, $data, $start, $len);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleStdOut($this);
    }

}