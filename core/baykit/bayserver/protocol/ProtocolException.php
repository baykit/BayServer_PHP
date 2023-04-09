<?php
namespace baykit\bayserver\protocol;


use baykit\bayserver\BayException;

class ProtocolException extends BayException
{
    public function __construct(?string $fmt, ...$args)
    {
        if ($fmt === null)
            $msg = "";
        elseif (count($args) == 0)
            $msg = sprintf("%s", $fmt);
        else
            $msg = sprintf($fmt, ...$args);

        parent::__construct($msg . "(>_<)");
    }
}