<?php
namespace baykit\bayserver\tour;


class ReqContentHandlerUtil {
    public static ReqContentHandler $devNull;
}

ReqContentHandlerUtil::$devNull = new class implements ReqContentHandler {
    public function onReadReqContent(Tour $tur, string $buf, int $start, int $len, ?callable $callback): void
    {
    }

    public function onEndReqContent(Tour $tur): void
    {
    }

    public function onAbortReq(Tour $tur): bool
    {
        return false;
    }
};