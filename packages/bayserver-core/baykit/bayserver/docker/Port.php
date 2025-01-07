<?php
namespace baykit\bayserver\docker;

use baykit\bayserver\common\InboundShip;
use baykit\bayserver\protocol\ProtocolHandler;
use baykit\bayserver\rudder\Rudder;

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
    public function additionalHeaders() : array;
    public function findCity(string $name) : ?City;
    public function onConnected(int $agtId, Rudder $rd) : void;
    public function returnProtocolHandler(int $agtId, ProtocolHandler $handler) : void;
    public function returnShip(InboundShip $sip) : void;
}