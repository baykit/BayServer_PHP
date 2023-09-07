<?php
namespace baykit\bayserver;


class ConfigException extends BayException
{
    public $fileName;
    public $lineNo;

    public function __construct($fileName, $lineNo, $fmt, ...$args) {
        if ($fmt === null)
            $msg = NULL;
        elseif (count($args) == 0)
            $msg = sprintf("%s", $fmt);
        else
            $msg = sprintf($fmt, $args);

        parent::__construct(self::createMessage($msg, $fileName, $lineNo));

        $this->fileName = $fileName;
        $this->lineNo = $lineNo;
    }

    public static function createMessage(string $msg, string $fname, int $lineNo) : string
    {
        return "{$msg} {$fname}:{$lineNo}";
    }
}