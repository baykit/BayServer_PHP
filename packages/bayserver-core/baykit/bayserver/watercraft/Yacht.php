<?php
namespace baykit\bayserver\watercraft;

use baykit\bayserver\agent\transporter\DataListener;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Sink;
use baykit\bayserver\util\Counter;

/**
 * Yacht wraps input stream
 */
abstract class Yacht implements DataListener
{
    const  INVALID_YACHT_ID = 0;
    static $oidCounter;
    static $idCounter;

    public $objectId;
    public $yachtId;

    public function __construct()
    {
        $this->objectId = self::$oidCounter->next();
        $this->yachtId = self::INVALID_YACHT_ID;
    }

    public function initYacht() : void
    {
        $this->yachtId = self::$idCounter->next();
    }

    public function notifyConnect() : int
    {
        throw new Sink();
    }

    public function notifyHandshakeDone(string $protocol)  : int
    {
        throw new Sink();
    }

    public function notifyProtocolError(ProtocolException $e) : bool
    {
        throw new Sink();
    }
}

Yacht::$oidCounter = new Counter();
Yacht::$idCounter = new Counter();