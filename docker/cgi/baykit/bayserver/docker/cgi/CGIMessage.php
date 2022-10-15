<?php
namespace baykit\bayserver\docker\cgi;

use baykit\bayserver\BayServer;
use baykit\bayserver\util\Locale;
use baykit\bayserver\util\Message;


class CGIMessage
{
    public static $msg;

    public static function init() : void
    {
        self::$msg = new Message();
        self::$msg->init(BayServer::$bservHome . "/lib/conf/cgi_messages", Locale::getDefault());
    }

    public static function get(string $key, object ...$args) : string
    {
        return self::$msg->get($key, $args);
    }
}

CGIMessage::init();