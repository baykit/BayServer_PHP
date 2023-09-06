<?php
namespace baykit\bayserver\protocol;


use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\util\Reusable;

abstract class ProtocolHandler implements Reusable
{
    public $packetUnpacker;
    public $packetPacker;
    public $commandUnpacker;
    public $commandPacker;
    public $packet_store;
    public $server_mode;
    public $ship;

    public function __toString()
    {
        return "PH ship={$this->ship}";
    }

    /////////////////////////////////////////////////////////////////////////////////
    // Abstract methods
    /////////////////////////////////////////////////////////////////////////////////

    public abstract function protocol() : string;

    /**
     * Get max of request data size (maybe not packet size)
     */
    public abstract function maxReqPacketDataSize() : int;

    /**
     * Get max of response data size (maybe not packet size)
     */
    public abstract function maxResPacketDataSize() : int;

    /////////////////////////////////////////////////////////////////////////////////
    // Implements Reusable
    /////////////////////////////////////////////////////////////////////////////////

    public function reset() : void
    {
        $this->commandUnpacker->reset();
        $this->commandPacker->reset();
        $this->packetUnpacker->reset();
        $this->packetPacker->reset();
    }

    /////////////////////////////////////////////////////////////////////////////////
    // Other methods
    /////////////////////////////////////////////////////////////////////////////////

    public function bytesReceived(string $buf) : int
    {
        return $this->packetUnpacker->bytesReceived($buf);
    }


}