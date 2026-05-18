<?php

namespace baykit\bayserver\docker\http\h1;

use baykit\bayserver\docker\http\h1\command\CmdContent;
use baykit\bayserver\docker\http\h1\command\CmdEndContent;
use baykit\bayserver\docker\http\h1\command\CmdHeader;
use baykit\bayserver\protocol\Command;
use baykit\bayserver\protocol\CommandFactory;

class H1CommandFactory extends CommandFactory
{
    public function createCommand(int $type) : Command
    {
        switch ($type) {
            case H1Type::HEADER:
                return new CmdHeader();
            case H1Type::CONTENT:
                return new CmdContent();
            case H1Type::END_CONTENT:
                return new CmdEndContent();
            default:
                throw new \InvalidArgumentException("Unknown H1 command type: $type");
        }
    }
}
