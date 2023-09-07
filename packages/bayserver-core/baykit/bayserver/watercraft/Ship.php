<?php
namespace baykit\bayserver\watercraft;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\BayLog;
use baykit\bayserver\Sink;
use baykit\bayserver\util\Counter;
use baykit\bayserver\util\Postman;
use baykit\bayserver\util\Reusable;

/**
 * Ship wraps TCP or UDP connection
 */
abstract Class Ship implements Reusable
{
    public static $oid_counter;
    public static $shipIdCounter;

    const SHIP_ID_NOCHECK = -1;
    const INVALID_SHIP_ID = 0;

    public $objectId;
    public $shipId = Ship::INVALID_SHIP_ID;
    public $agent;
    public $postman;
    public $socket;
    public $initialized;
    public $protocolHandler;
    public $keeping;

    public function __construct()
    {
        $this->objectId = self::$oid_counter->next();
    }

    ######################################################
    # implements Reusable
    ######################################################

    public function reset() : void
    {
        BayLog::debug("%s reset", $this);

        $this->initialized = false;
        $this->postman->reset();
        $this->postman = null;  # for reloading certification
        $this->agent = null;
        $this->shipId = Ship::INVALID_SHIP_ID;
        $this->socket = null;
        $this->protocolHandler = null;
        $this->keeping = false;
    }

    ######################################################
    # Other methods
    ######################################################

    public function init($skt, GrandAgent $agt, Postman $pm) : void
    {
        if ($this->initialized)
            throw new Sink("ship already initialized");

        $this->shipId = Ship::$shipIdCounter->next();
        $this->agent = $agt;
        $this->postman = $pm;
        $this->socket = $skt;
        $this->initialized = true;
        BayLog::debug("%s initialized: %s", $this, $skt);
    }

    public function setProtocolHandler($hnd) : void
    {
        $this->protocolHandler = $hnd;
        $hnd->ship = $this;
        BayLog::debug("%s protocol handler is set", $this);
    }

    public function id() : string
    {
        return $this->shipId;
    }

    public function protocol() : string
    {
        if ($this->protocolHandler === null)
            return "unknown";
        else
            return $this->protocolHandler->protocol();;

    }

    public function resume($chk_id) : void
    {
        $this->checkShipId($chk_id);
        $this->postman->openValve();
    }

    public function checkShipId($chk_id) : void
    {
        if (!$this->initialized)
            throw new Sink("%s ships not initialized (might be returned ships): %d", $this, $chk_id);

        if ($chk_id != self::SHIP_ID_NOCHECK and $chk_id != $this->shipId)
            throw new Sink("%s Invalid ships id (might be returned ships): %d", $this, $chk_id);
    }
}

Ship::$oid_counter = new Counter();
Ship::$shipIdCounter = new Counter();
