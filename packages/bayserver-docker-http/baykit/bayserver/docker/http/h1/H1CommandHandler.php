<?php

namespace baykit\bayserver\docker\http\h1;

use baykit\bayserver\docker\http\h1\command\CmdContent;
use baykit\bayserver\docker\http\h1\command\CmdEndContent;
use baykit\bayserver\docker\http\h1\command\CmdHeader;
use baykit\bayserver\protocol\CommandHandler;

interface H1CommandHandler extends CommandHandler {

    public function handleHeader(CmdHeader $cmd) : int;

    public function handleContent(CmdContent $cmd) : int;

    public function handleEndContent(CmdEndContent $cmdEndContent) : int;

    public function reqFinished() : bool;
}