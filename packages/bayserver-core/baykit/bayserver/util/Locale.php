<?php

namespace baykit\bayserver\util;

use baykit\bayserver\BayLog;

class Locale {
    public $language = NULL;
    public $country = NULL;

    public function __construct($lang, $cnt)
    {
        $this->language = $lang;
        $this->country = $cnt;
    }

    public static function getDefault() : Locale
    {
        $lang = getenv("LANG");
        if (StringUtil::isSet($lang)) {
            try {
                $language = substr($lang, 0, 2);
                $country = substr($lang, 3, 2);
                return new Locale($language, $country);
            }
            catch(\Exception $e) {
                BayLog::error_e($e);
            }
        }
        return new Locale("en", "US");
    }
}
