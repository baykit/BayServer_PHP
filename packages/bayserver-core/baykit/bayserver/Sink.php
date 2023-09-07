<?php
namespace baykit\bayserver;

class Sink extends \Exception
{
    public function __construct(?string $fmt=null, ...$args)
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