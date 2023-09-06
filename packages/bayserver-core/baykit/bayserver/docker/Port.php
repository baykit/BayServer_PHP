<?php
namespace baykit\bayserver\docker;

interface Port extends Docker
{
    public function protocol() : string;
    public function host() : ?string;
    public function port() : int;
    public function socketPath() : ?string;
    public function address() : array;
    public function anchored() : bool;
    public function secure() : bool;
    public function timeoutSec() : int;
    public function checkAdmitted($skt) : void;
    public function additionalHeaders() : array;
    public function findCity(string $name) : ?City;
    public function newTransporter($agt, $skt);
    public function returnProtocolHandler($agt, $handler) : void;
    public function returnShip($sip) : void;
}