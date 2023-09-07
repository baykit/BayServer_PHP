<?php
namespace baykit\bayserver\agent\transporter;

use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\util\Reusable;


interface DataListener
{
    public function notifyConnect() : int;
    public function notifyRead(string $buf, ?array $adr) : int;
    public function notifyEof() : int;
    public function notifyHandshakeDone(string $protocol) : int;

    public function notifyProtocolError(ProtocolException $e) : bool;
    public function notifyClose() : void;

    public function checkTimeout(int $durationSec) : bool;
}