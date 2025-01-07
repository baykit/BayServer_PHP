<?php
namespace baykit\bayserver\ship;

use baykit\bayserver\BayLog;
use baykit\bayserver\common\Transporter;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\Sink;
use baykit\bayserver\util\Counter;
use baykit\bayserver\util\Reusable;

/**
 * Ship wraps TCP or UDP connection
 */
abstract Class Ship implements Reusable
{
    public static Counter $oid_counter;
    public static Counter $shipIdCounter;

    const SHIP_ID_NOCHECK = -1;
    const INVALID_SHIP_ID = 0;

    public int $objectId;
    public int $shipId = Ship::INVALID_SHIP_ID;
    public int $agentId;
    public ?Rudder $rudder;
    public ?Transporter $transporter;
    public bool $initialized = false;
    public bool $keeping = false;

    public function __construct()
    {
        $this->objectId = self::$oid_counter->next();
    }

    public function init(int $agtId, Rudder $rd, ?Transporter $tp) : void
    {
        if ($this->initialized)
            throw new Sink("ship already initialized");

        $this->shipId = Ship::$shipIdCounter->next();
        $this->agentId = $agtId;
        $this->rudder = $rd;
        $this->transporter = $tp;
        $this->initialized = true;
        BayLog::debug("%s initialized", $this);
    }


    ######################################################
    # implements Reusable
    ######################################################

    public function reset() : void
    {
        BayLog::debug("%s reset", $this);

        $this->initialized = false;
        $this->transporter = null;
        $this->rudder = null;
        $this->agentId = -1;
        $this->shipId = Ship::INVALID_SHIP_ID;
        $this->keeping = false;
    }

    ######################################################
    # Other methods
    ######################################################

    public function id() : string
    {
        return $this->shipId;
    }

    public function checkShipId($chk_id) : void
    {
        if (!$this->initialized)
            throw new Sink("%s ships not initialized (might be returned ships): %d", $this, $chk_id);

        if ($chk_id != self::SHIP_ID_NOCHECK and $chk_id != $this->shipId)
            throw new Sink("%s Invalid ships id (might be returned ships): %d", $this, $chk_id);
    }

    public function resumeRead(int $chkId) : void
    {
        $this->checkShipId($chkId);
        BayLog::debug("%s resume read", $this);
        $this->transporter->reqRead($this->rudder);
    }

    public function postClose(): void
    {
        $this->transporter->reqClose($this->rudder);
    }

    /////////////////////////////////////
    // Abstract methods
    /////////////////////////////////////

    public abstract function notifyHandshakeDone(string $pcl) : int;
    public abstract function notifyConnect() :int;
    public abstract function notifyRead(string $buf) : int;
    public abstract function notifyEof(): int;
    public abstract function notifyError(\Exception $e): void;
    public abstract function notifyProtocolError(ProtocolException $e): bool;
    public abstract function notifyClose(): void;
    public abstract function checkTimeout(int $durationSec): bool;
}

Ship::$oid_counter = new Counter();
Ship::$shipIdCounter = new Counter();
