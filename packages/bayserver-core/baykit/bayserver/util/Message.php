<?php

namespace baykit\bayserver\util;

use baykit\bayserver\BayLog;
use baykit\bayserver\bcf\BcfParser;
use baykit\bayserver\bcf\BcfKeyVal;


class Message {

    public $messages = array();

    public function __construct()
    {
    }

    public function init($filePrefix, $locale)
    {
        $lang = $locale->language;
        $file = $filePrefix . ".bcf";
        if (StringUtil::isSet($lang) && $lang != "en")
            $file = $filePrefix . "_" . $lang . ".bcf";

        if (!is_file($file)) {
            BayLog::warn("Cannot find message file: " . $file);
            return false;
        }

        $p = new BcfParser();
        $doc = $p->parse($file);

        foreach ($doc->contentList as $o) {
            if ($o instanceof BcfKeyVal) {
                $this->messages[$o->key] = $o->value;
            }
        }
    }

    public function get($key, ...$args)
    {
        $msg = $this->messages[$key];
        if ($msg === false)
            $msg = $key;
        return sprintf($msg, ...$args);
    }
}