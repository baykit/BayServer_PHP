<?php

namespace baykit\bayserver\util;

use baykit\bayserver\BayLog;

class StringUtil {

    const TRUES = ["yes", "true", "on", "1"];
    const FALSES = ["no", "false", "off", "0"];

    public static function startsWith(string $haystack, string $needle): bool
    {
        return strpos($haystack, $needle) === 0;
    }

    public static function endsWith(string $haystack, string $needle): bool
    {
        return strrpos($haystack, $needle) === strlen($haystack) - strlen($needle);
    }

    public static function isEmpty(?string $str): bool
    {
        return $str == "";
    }

    public static function isSet(?string $str): bool
    {
        return !self::isEmpty($str);
    }

    public static function eqIgnorecase(string $a, string $b): bool
    {
        return strcasecmp($a, $b) == 0;
    }

    public static function parseBool(string $val) : bool
    {
        $val = strtolower($val);
        if (in_array($val, StringUtil::TRUES))
            return true;
        elseif(in_array($val, StringUtil::FALSES))
            return false;
        else {
            BayLog::warn("Invalid boolean value(set false): %s", $val);
            return false;
        }
    }

    public static function parseSize(string $value) : int
    {
        $value = strtolower($value);
        $rate = 1;
        if(self::endsWith($value, "b"))
            $value = substr($value, 0, strlen($value) - 1);
        if(self::endsWith($value, "k")) {
            $value = substr($value, 0, strlen($value) - 1);
            $rate = 1024;
        }
        elseif(self::endsWith($value, "m")) {
            $value = substr($value, 0, strlen($value) - 1);
            $rate = 1024 * 1024;
        }

        return intval($value) * $rate;
    }

    public static function  parseCharset(string $charset) : ?string
    {
        try {
            $s = \mb_convert_encoding("", "utf-8", $charset);
            if ($s === false)
                throw new \ValueError("invalid charset: " . $charset);
        } catch (\Error $e) {
            BayLog::warn("Cannot check encoding: %s", $e->getMessage());
            return $charset;
        }
        return $charset;
    }

    public static function allocate(int $len) : string
    {
        return str_repeat(chr(0), $len);
    }
}