<?php
namespace baykit\bayserver\util;


class BlockingIOException extends IOException
{
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}