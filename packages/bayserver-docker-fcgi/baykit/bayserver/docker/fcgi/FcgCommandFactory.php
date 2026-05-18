<?php

namespace baykit\bayserver\docker\fcgi;

use baykit\bayserver\docker\fcgi\command\CmdBeginRequest;
use baykit\bayserver\docker\fcgi\command\CmdEndRequest;
use baykit\bayserver\docker\fcgi\command\CmdParams;
use baykit\bayserver\docker\fcgi\command\CmdStdErr;
use baykit\bayserver\docker\fcgi\command\CmdStdIn;
use baykit\bayserver\docker\fcgi\command\CmdStdOut;
use baykit\bayserver\protocol\Command;
use baykit\bayserver\protocol\CommandFactory;

class FcgCommandFactory extends CommandFactory
{
    public function createCommand(int $type) : Command
    {
        switch ($type) {
            case FcgType::BEGIN_REQUEST: return new CmdBeginRequest();
            case FcgType::END_REQUEST:   return new CmdEndRequest();
            case FcgType::PARAMS:        return new CmdParams();
            case FcgType::STDIN:         return new CmdStdIn();
            case FcgType::STDOUT:        return new CmdStdOut();
            case FcgType::STDERR:        return new CmdStdErr();
            default:
                throw new \InvalidArgumentException("Unknown FCGI command type: $type");
        }
    }
}
