<?php

namespace baykit\bayserver\docker\http\h2;

use baykit\bayserver\BayServer;
use baykit\bayserver\util\Locale;
use baykit\bayserver\util\Message;

class H2ErrorCode extends Message
{
    const NO_ERROR = 0x0;
    const PROTOCOL_ERROR = 0x1;
    const INTERNAL_ERROR = 0x2;
    const FLOW_CONTROL_ERROR = 0x3;
    const SETTINGS_TIMEOUT = 0x4;
    const STREAM_CLOSED = 0x5;
    const FRAME_SIZE_ERROR = 0x6;
    const REFUSED_STREAM = 0x7;
    const CANCEL = 0x8;
    const COMPRESSION_ERROR = 0x9;
    const CONNECT_ERROR = 0xa;
    const ENHANCE_YOUR_CALM = 0xb;
    const INADEQUATE_SECURITY = 0xc;
    const HTTP_1_1_REQUIRED = 0xd;

    public static $desc = [];
    public static $msg;

    public static function initCodes() : void
    {
        if (self::$msg !== null)
            return;

        $prefix = BayServer::$bservHome . "/lib/conf/h2_messages";
        self::$msg = new H2ErrorCode();
        self::$msg->init($prefix, new Locale('ja', 'JP'));
    }
}