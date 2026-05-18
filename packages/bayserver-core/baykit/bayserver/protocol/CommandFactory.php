<?php

namespace baykit\bayserver\protocol;

abstract class CommandFactory
{
    public abstract function createCommand(int $type) : Command;
}
