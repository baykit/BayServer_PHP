<?php
namespace baykit\bayserver\watercraft;

use baykit\bayserver\agent\transporter\DataListener;
use baykit\bayserver\BayLog;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Sink;
use baykit\bayserver\util\Counter;

/**
 * Boat wraps output stream
 */
abstract class Boat implements DataListener
{
    const  INVALID_BOAT_ID = 0;
    static $oidCounter;
    static $idCounter;

    public $objectId;
    public $boartId;

    public function __construct()
    {
        $this->objectId = self::$oidCounter->next();
        $this->boartId = self::INVALID_BOAT_ID;
    }

    public function init() : void
    {
        $this->boartId = self::$idCounter->next();
    }

    public function notifyConnect() : int
    {
        throw new Sink();
    }

    public function notifyRead(string $buf, ?array $adr) : int
    {
        throw new Sink();
    }

    public function notifyEof() : int
    {
        throw new Sink();
    }

    public function notifyHandshakeDone(string $protocol) : int
    {
        throw new Sink();
    }

    public function notifyProtocolError(ProtocolException $e) : bool
    {
        throw new Sink();
    }

    public function notifyClose() : void
    {
        throw new Sink();
    }

    public function checkTimeout(int $durationSec) : bool
    {
        return false;
    }
}

Boat::$oidCounter = new Counter();
Boat::$idCounter = new Counter();