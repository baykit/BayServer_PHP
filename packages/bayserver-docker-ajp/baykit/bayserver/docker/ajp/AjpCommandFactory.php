<?php

namespace baykit\bayserver\docker\ajp;

use baykit\bayserver\docker\ajp\command\CmdData;
use baykit\bayserver\docker\ajp\command\CmdEndResponse;
use baykit\bayserver\docker\ajp\command\CmdForwardRequest;
use baykit\bayserver\docker\ajp\command\CmdGetBodyChunk;
use baykit\bayserver\docker\ajp\command\CmdSendBodyChunk;
use baykit\bayserver\docker\ajp\command\CmdSendHeaders;
use baykit\bayserver\docker\ajp\command\CmdShutdown;
use baykit\bayserver\protocol\Command;
use baykit\bayserver\protocol\CommandFactory;

class AjpCommandFactory extends CommandFactory
{
    public function createCommand(int $type) : Command
    {
        switch ($type) {
            case AjpType::DATA:             return new CmdData();
            case AjpType::FORWARD_REQUEST:  return new CmdForwardRequest();
            case AjpType::SEND_BODY_CHUNK:  return new CmdSendBodyChunk();
            case AjpType::SEND_HEADERS:     return new CmdSendHeaders();
            case AjpType::END_RESPONSE:     return new CmdEndResponse();
            case AjpType::GET_BODY_CHUNK:   return new CmdGetBodyChunk();
            case AjpType::SHUTDOWN:         return new CmdShutdown();
            default:
                throw new \InvalidArgumentException("Unknown AJP command type: $type");
        }
    }
}
