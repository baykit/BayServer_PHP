<?php
namespace baykit\bayserver\docker\h3;

use baykit\bayserver\docker\h3\command\CmdData;
use baykit\bayserver\docker\h3\command\CmdFinished;
use baykit\bayserver\docker\h3\command\CmdHeader;
use baykit\bayserver\protocol\CommandHandler;

/**
 * Receives logical H3 events (HEADERS / DATA / FINISHED) emitted by
 * QicProtocolHandler when libquiche's H3 poll() returns. Mirrors
 * the upstream QicCommandHandler.
 */
interface QicCommandHandler extends CommandHandler
{
    public function handleHeaders(CmdHeader $cmd) : void;
    public function handleData(CmdData $cmd) : void;
    public function handleFinished(CmdFinished $cmd) : void;
}
