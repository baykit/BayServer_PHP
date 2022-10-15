<?php
namespace baykit\bayserver\util;

class ArrayUtil
{
    public static function remove(object $needle, array &$arr) : void
    {
        $pos = array_search($needle, $arr, true);
        if($pos !== false)
            array_splice($arr, $pos, 1);
    }

    public static function removeByIndex(int $idx, array &$arr) : void
    {
        array_splice($arr, $idx, 1);
    }

    public static function insert(object $needle, array &$arr, int $idx) : void
    {
        array_splice( $arr, $idx, 0, [$needle]);
    }

    public static function get($key, array $arr)
    {
        if(array_key_exists($key, $arr))
            return $arr[$key];
        else
            return null;
    }

    public static function toString(array $arr) : string
    {
        $ret = null;
        foreach($arr as $key => $val) {
            if($ret)
                $ret .= ", ";
            else
                $ret = "";
            $ret .= "{$key}={$val}";
        }
        return "[" . $ret . "]";
    }
}