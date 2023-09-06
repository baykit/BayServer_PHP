<?php
namespace baykit\bayserver\tour;


interface ReqContentHandler {

    public function onReadContent(Tour $tur, string $buf, int $start, int $len) : void;

    public function onEndContent(Tour $tur) : void;

    public function onAbort(Tour $tur) : bool;
}