<?php

namespace baykit\bayserver\docker\http\h2;

use baykit\bayserver\docker\http\h2\command\CmdData;
use baykit\bayserver\docker\http\h2\command\CmdGoAway;
use baykit\bayserver\docker\http\h2\command\CmdHeaders;
use baykit\bayserver\docker\http\h2\command\CmdPing;
use baykit\bayserver\docker\http\h2\command\CmdPreface;
use baykit\bayserver\docker\http\h2\command\CmdPriority;
use baykit\bayserver\docker\http\h2\command\CmdRstStream;
use baykit\bayserver\docker\http\h2\command\CmdSettings;
use baykit\bayserver\docker\http\h2\command\CmdWindowUpdate;
use baykit\bayserver\protocol\CommandHandler;

interface H2CommandHandler extends CommandHandler {

    public function handlePreface(CmdPreface $cmd) : int;

    public function handleData(CmdData $cmd) : int;

    public function handleHeaders(CmdHeaders $cmd) : int;

    public function handlePriority(CmdPriority $cmd) : int;

    public function handleSettings(CmdSettings $cmd) : int;

    public function handleWindowUpdate(CmdWindowUpdate $cmd) : int;

    public function handleGoAway(CmdGoAway $cmd) : int;

    public function handlePing(CmdPing $cmd) : int;

    public function handleRstStream(CmdRstStream $cmd) : int;

}