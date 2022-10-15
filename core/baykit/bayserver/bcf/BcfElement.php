<?php

namespace baykit\bayserver\bcf;

use baykit\bayserver\util\StringUtil;

class BcfElement extends BcfObject
{
    public function __construct($name, $arg, $fileName, $lineNo)
    {
        parent::__construct($fileName, $lineNo);
        $this->name = $name;
        $this->arg = $arg;
        $this->contentList = [];
    }

    public function getValue($key)
    {
        foreach ($this->contentList as $o) {
            if (($o instanceof BcfKeyVal) && StringUtil::eqIgnorecase($o->key, $key))
                return $o->value;
        }
        return NULL;
    }
}