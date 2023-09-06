<?php

namespace baykit\bayserver\docker\http\h1;

use \baykit\bayserver\protocol\Command;

abstract class H1Command extends Command {
    public function __construct(int $type) {
        parent::__construct($type);
    }
}