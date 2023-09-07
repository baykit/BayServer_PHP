<?php

namespace baykit\bayserver\util;

use baykit\bayserver\BayLog;

class URLDecoder {

    public static function decode(string $str, ?string $charset) : string
    {
        $decoded  = urldecode($str);
        if($charset !== null) {
            try {
                $decoded = mb_convert_encoding($decoded, $charset, mb_internal_encoding());
            } catch (\Error $e) {
                BayLog::warn("Cannot convert encoding: %s", $e->getMessage());
            }
        }
        return $decoded;
    }
}
