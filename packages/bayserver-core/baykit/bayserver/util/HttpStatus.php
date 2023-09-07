<?php
namespace baykit\bayserver\util;

use baykit\bayserver\bcf\BcfParser;
use baykit\bayserver\bcf\BcfKeyVal;

class HttpStatus {
    #
    # Known status
    #
    const OK = 200;
    const MOVED_PERMANENTLY = 301;
    const MOVED_TEMPORARILY = 302;
    const NOT_MODIFIED = 304;
    const BAD_REQUEST = 400;
    const UNAUTHORIZED = 401;
    const FORBIDDEN = 403;
    const NOT_FOUND = 404;
    const UPGRADE_REQUIRED = 426;
    const INTERNAL_SERVER_ERROR = 500;
    const SERVICE_UNAVAILABLE = 503;
    const GATEWAY_TIMEOUT = 504;
    const HTTP_VERSION_NOT_SUPPORTED = 505;

    public static $status = [];
    public static $initialized = false;

    public static function init($bcf_file)
    {
        if (self::$initialized)
            return;

        $p = new BcfParser();
        $doc = $p->parse($bcf_file);
        foreach ($doc->contentList as $kv) {
            if ($kv instanceof BcfKeyVal)
                self::$status[intval($kv->key)] = $kv->value;
        }

        self::$initialized = true;
    }

    public static function description($statusCode)
    {
        $desc = self::$status[$statusCode];
        if ($desc === false)
            return strval($statusCode);
        else
            return $desc;
    }
}