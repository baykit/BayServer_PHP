<?php
namespace baykit\bayserver\bcf;

class BcfObject
{
    public $fileName;
    public $lineNo;

    public function __construct($fileName, $lineNo)
    {
        $this->fileName = $fileName;
        $this->lineNo = $lineNo;
    }
}