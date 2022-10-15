<?php

namespace baykit\bayserver;

use baykit\bayserver\util\Message;

class BayMessage
{
    public static $msg = NULL;

    public static function init($conf_name, $locale)
    {
        self::$msg = new Message();
        self::$msg->init($conf_name, $locale);
    }

    public static function get($key, ...$args)
    {
        return self::$msg->get($key, ...$args);
    }
}