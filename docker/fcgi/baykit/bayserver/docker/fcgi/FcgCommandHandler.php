<?php

namespace baykit\bayserver\docker\fcgi;

use baykit\bayserver\docker\fcgi\command\CmdBeginRequest;
use baykit\bayserver\docker\fcgi\command\CmdEndRequest;
use baykit\bayserver\docker\fcgi\command\CmdParams;
use baykit\bayserver\docker\fcgi\command\CmdStdErr;
use baykit\bayserver\docker\fcgi\command\CmdStdIn;
use baykit\bayserver\docker\fcgi\command\CmdStdOut;
use baykit\bayserver\protocol\CommandHandler;

interface FcgCommandHandler extends CommandHandler {

    public function handleBeginRequest(CmdBeginRequest $cmd)  : int;

    public function handleEndRequest(CmdEndRequest $cmd) : int;

    public function handleParams(CmdParams $cmd) : int;

    public function handleStdErr(CmdStdErr $cmd) : int;

    public function handleStdIn(CmdStdIn $cmd) : int;

    public function handleStdOut(CmdStdOut $cmd) : int;
}