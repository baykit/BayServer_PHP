<?php

namespace baykit\bayserver\docker\ajp;

use baykit\bayserver\docker\ajp\command\CmdData;
use baykit\bayserver\docker\ajp\command\CmdEndResponse;
use baykit\bayserver\docker\ajp\command\CmdForwardRequest;
use baykit\bayserver\docker\ajp\command\CmdGetBodyChunk;
use baykit\bayserver\docker\ajp\command\CmdSendBodyChunk;
use baykit\bayserver\docker\ajp\command\CmdSendHeaders;
use baykit\bayserver\docker\ajp\command\CmdShutdown;
use baykit\bayserver\protocol\CommandHandler;

interface AjpCommandHandler extends CommandHandler {

    public function handleData(CmdData $cmd) : int;

    public function handleEndResponse(CmdEndResponse $cmd) : int;

    public function handleForwardRequest(CmdForwardRequest $cmd) : int;

    public function handleSendBodyChunk(CmdSendBodyChunk $cmd) : int;

    public function handleSendHeaders(CmdSendHeaders $cmd) : int;

    public function handleShutdown(CmdShutdown $cmd) : int;

    public function handleGetBodyChunk(CmdGetBodyChunk $cmd) : int;

    public function needData() : bool;
}