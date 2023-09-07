<?php
namespace baykit\bayserver\bcf;

use baykit\bayserver\ConfigException;

class ParseException extends ConfigException
{
    public function __construct($fileName, $lineNo, $msg) {
        parent::__construct($fileName, $lineNo, $msg);
    }
}