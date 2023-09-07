<?php
namespace baykit\bayserver\tour;


class ReqContentHandlerUtil {
    public static $devNull;
}

ReqContentHandlerUtil::$devNull = new class implements ReqContentHandler {
    public function onReadContent(Tour $tur, string $buf, int $start, int $len): void
    {
    }

    public function onEndContent(Tour $tur): void
    {
    }

    public function onAbort(Tour $tur): bool
    {
        return false;
    }
};