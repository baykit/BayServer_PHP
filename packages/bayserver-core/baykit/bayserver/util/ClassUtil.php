<?php
namespace baykit\bayserver\util;

class ClassUtil
{
    public static function localName($clazz) : string
    {
        $p = strrpos($clazz, '.');
        if ($p !== false)
            $clazz = substr($clazz, $p + 1);
        return $clazz;
    }
}