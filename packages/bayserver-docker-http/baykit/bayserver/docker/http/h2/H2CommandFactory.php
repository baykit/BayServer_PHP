<?php

namespace baykit\bayserver\docker\http\h2;

use baykit\bayserver\docker\http\h2\command\CmdContinuation;
use baykit\bayserver\docker\http\h2\command\CmdData;
use baykit\bayserver\docker\http\h2\command\CmdGoAway;
use baykit\bayserver\docker\http\h2\command\CmdHeaders;
use baykit\bayserver\docker\http\h2\command\CmdPing;
use baykit\bayserver\docker\http\h2\command\CmdPreface;
use baykit\bayserver\docker\http\h2\command\CmdPriority;
use baykit\bayserver\docker\http\h2\command\CmdRstStream;
use baykit\bayserver\docker\http\h2\command\CmdSettings;
use baykit\bayserver\docker\http\h2\command\CmdWindowUpdate;
use baykit\bayserver\protocol\Command;
use baykit\bayserver\protocol\CommandFactory;

class H2CommandFactory extends CommandFactory
{
    public function createCommand(int $type) : Command
    {
        switch ($type) {
            case H2Type::PREFACE:       return new CmdPreface();
            case H2Type::DATA:          return new CmdData();
            case H2Type::HEADERS:       return new CmdHeaders();
            case H2Type::PRIORITY:      return new CmdPriority();
            case H2Type::RST_STREAM:    return new CmdRstStream();
            case H2Type::SETTINGS:      return new CmdSettings();
            case H2Type::PING:          return new CmdPing();
            case H2Type::GOAWAY:        return new CmdGoAway();
            case H2Type::WINDOW_UPDATE: return new CmdWindowUpdate();
            case H2Type::CONTINUATION:  return new CmdContinuation();
            default:
                throw new \InvalidArgumentException("Unknown H2 command type: $type");
        }
    }
}
