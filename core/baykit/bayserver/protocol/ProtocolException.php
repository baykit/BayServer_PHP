<?php
namespace baykit\bayserver\protocol;


use baykit\bayserver\BayException;

class ProtocolException extends BayException
{
    public function __construct(?string $fmt, ...$args)
    {
        if ($fmt === null)
            $msg = "";
        else
            $msg = sprintf($fmt, ...$args);

        parent::__construct($msg . "(>_<)");
    }
}