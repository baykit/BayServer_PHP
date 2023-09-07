<?php

namespace baykit\bayserver;

class BayException extends \Exception
{
    public function __construct($fmt, ...$args) {
        if ($fmt === null)
            $msg = null;
        elseif (count($args) == 0)
            $msg = sprintf("%s", $fmt);
        else
            $msg = sprintf($fmt, $args);

        parent::__construct($msg);
    }
}