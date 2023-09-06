<?php

namespace baykit\bayserver\docker\ajp\command;

use baykit\bayserver\docker\ajp\AjpCommand;
use baykit\bayserver\docker\ajp\AjpType;
use baykit\bayserver\protocol\CommandHandler;


/**
 * Shutdown command format
 *
 *   none
 */
class CmdShutdown extends AjpCommand
{
    public function __construct()
    {
        parent::__construct(AjpType::SHUTDOWN, true);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleShutdown($this);
    }
}