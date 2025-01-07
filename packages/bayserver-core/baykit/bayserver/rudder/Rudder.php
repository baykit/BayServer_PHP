<?php
namespace baykit\bayserver\rudder;

use baykit\bayserver\agent\transporter\DataListener;
use baykit\bayserver\BayLog;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Sink;
use baykit\bayserver\util\Counter;

interface Rudder
{
    public function key();

    public function setNonBlocking(): void;

    // Returns "" when reached EOF
    public function read(int $len) : ?string;

    public function write(string $buf) : int;

    public function close(): void;
}
