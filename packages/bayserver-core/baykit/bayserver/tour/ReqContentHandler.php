<?php
namespace baykit\bayserver\tour;


interface ReqContentHandler {

    public function onReadReqContent(Tour $tur, string $buf, int $start, int $len, ?callable $callback): void;

    public function onEndReqContent(Tour $tur) : void;

    public function onAbortReq(Tour $tur) : bool;
}