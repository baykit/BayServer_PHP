<?php
namespace baykit\bayserver;

class Sink extends \Exception
{
    public function __construct(?string $fmt=null, ...$args)
    {
        if ($fmt === null)
            $msg = "";
        else
            $msg = sprintf($fmt, ...$args);

        parent::__construct($msg . "(>_<)");
    }
}