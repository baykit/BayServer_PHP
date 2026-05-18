<?php
namespace baykit\bayserver\protocol;


use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\ship\Ship;
use baykit\bayserver\util\Reusable;

abstract class ProtocolHandler implements Reusable
{
    public PacketUnPacker $packetUnpacker;
    public PacketPacker $packetPacker;
    public CommandUnPacker $commandUnpacker;
    public CommandPacker $commandPacker;
    public CommandHandler $commandHandler;
    // Per-protocol Command object pool; PHP-specific (Java's ProtocolHandler
    // does not have this field). H3 / QicProtocolHandler does not use it
    // (= QicNull{CommandUn,}Packer never rent commands), so the slot is
    // nullable to mirror Java's super(...) call shape (handler + serverMode
    // only). All real H1/H2/FCGI/AJP subclasses still pass a concrete store.
    public ?CommandStore $commandStore;
    public bool $server_mode;
    public ?Ship $ship = null;

    public function __construct(PacketUnPacker $packetUnpacker,
        PacketPacker $packetPacker,
        CommandUnPacker $commandUnpacker,
        CommandPacker $commandPacker,
        CommandHandler $commandHandler,
        ?CommandStore $commandStore,
        bool $server_mode)
    {
        $this->packetUnpacker = $packetUnpacker;
        $this->packetPacker = $packetPacker;
        $this->commandUnpacker = $commandUnpacker;
        $this->commandPacker = $commandPacker;
        $this->commandHandler = $commandHandler;
        $this->commandStore = $commandStore;
        $this->server_mode = $server_mode;
    }


    public function __toString()
    {
        return "PH ship={$this->ship}";
    }

    public function init(Ship $sip)
    {
        $this->ship = $sip;
    }

    ////////////////////////////////////////////
    // Abstract methods
    ////////////////////////////////////////////

    public abstract function protocol() : string;

    /**
     * Get max of request data size (maybe not packet size)
     */
    public abstract function maxReqPacketDataSize() : int;

    /**
     * Get max of response data size (maybe not packet size)
     */
    public abstract function maxResPacketDataSize() : int;

    ////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////

    public function reset() : void
    {
        $this->commandUnpacker->reset();
        $this->commandPacker->reset();
        $this->packetUnpacker->reset();
        $this->packetPacker->reset();
        $this->commandHandler->reset();
        $this->ship = null;
    }

    ////////////////////////////////////////////
    // Other methods
    ////////////////////////////////////////////

    public function bytesReceived(string $buf) : int
    {
        return $this->packetUnpacker->bytesReceived($buf);
    }

    public function post(Command $cmd, bool $flush, ?callable $callback = null) : bool
    {
        return $this->commandPacker->post($this->ship, $cmd, $flush, $callback);
    }


}