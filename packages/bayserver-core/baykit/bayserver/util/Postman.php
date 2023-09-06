<?php
namespace baykit\bayserver\util;

interface Postman
{
    public function post(string $buf, ?array $adr, $tag, ?callable $lis) : void;

    public function flush() : void;

    public function postEnd() : void;

    public function isZombie() : bool;

    public function abort() : void;

    public function openValve() : void;
}
