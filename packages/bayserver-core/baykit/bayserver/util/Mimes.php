<?php
namespace baykit\bayserver\util;

use baykit\bayserver\bcf\BcfParser;
use baykit\bayserver\bcf\BcfKeyVal;

class Mimes {
    public static $mimeMap = [];

    public static function init($bcfFile)
    {
        $p = new BcfParser();
        $doc = $p->parse($bcfFile);
        foreach($doc->contentList as $kv) {
            if ($kv instanceof BcfKeyVal)
                self::$mimeMap[$kv->key] = $kv->value;
        }
    }


    public static function type(string $ext) : ?string
    {
        $ext = strtolower($ext);
        if(!array_key_exists($ext, self::$mimeMap))
            return null;
        else
            return self::$mimeMap[$ext];
    }
}