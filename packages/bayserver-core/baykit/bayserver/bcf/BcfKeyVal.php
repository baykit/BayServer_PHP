<?php
namespace baykit\bayserver\bcf;

class BcfKeyVal extends BcfObject {

    public $key;
    public $value;

    public function __construct($key, $val, $fileName, $lineNo)
    {
        parent::__construct($fileName, $lineNo);
        $this->key = $key;
        $this->value = $val;
    }
}