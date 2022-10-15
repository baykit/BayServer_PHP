<?php

namespace baykit\bayserver\docker\http\h2\command;

use baykit\bayserver\docker\http\h2\H2Command;
use baykit\bayserver\docker\http\h2\H2DataAccessor;
use baykit\bayserver\docker\http\h2\H2Flags;
use baykit\bayserver\docker\http\h2\H2Packet;
use baykit\bayserver\docker\http\h2\H2Type;
use baykit\bayserver\docker\http\h2\HeaderBlock;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;



/**
 * HTTP/2 Header payload format
 *
 * +---------------+
 * |Pad Length? (8)|
 * +-+-------------+-----------------------------------------------+
 * |E|                 Stream Dependency? (31)                     |
 * +-+-------------+-----------------------------------------------+
 * |  Weight? (8)  |
 * +-+-------------+-----------------------------------------------+
 * |                   Header Block Fragment (*)                 ...
 * +---------------------------------------------------------------+
 * |                           Padding (*)                       ...
 * +---------------------------------------------------------------+
 */
class CmdHeaders extends H2Command
{
    public $padLength;
    public $excluded;
    public $streamDependency;
    public $weight;
    public $headerBlocks = [];

    public function __construct(int $streamId, H2Flags $flags = null)
    {
        parent::__construct(H2Type::HEADERS, $streamId, $flags);
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);

        $acc = $pkt->newH2DataAccessor();

        if($pkt->flags->padded())
            $this->padLength = $acc->getByte();
        if($pkt->flags->priority()) {
            $val = $acc->getInt();
            $this->excluded = H2Packet::extractFlag($val) == 1;
            $this->streamDependency = H2Packet::extractInt31($val);
            $this->weight = $acc->getByte();
        }
        $this->readHeaderBlock($acc, $pkt->dataLen());
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newH2DataAccessor();

        if($this->flags->padded()) {
            $acc->putByte($this->padLength);
        }
        if($this->flags->priority()) {
            $acc->putInt(H2Packet::makeStreamDependency32($this->excluded, $this->streamDependency));
            $acc->putByte($this->weight);
        }
        $this->writeHeaderBlock($acc);
        parent::pack($pkt);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleHeaders($this);
    }

    private function readHeaderBlock(H2DataAccessor $acc, int $len) : void
    {
        while($acc->pos < $len) {
            $blk = HeaderBlock::unpack($acc);
            $this->headerBlocks[] = $blk;
        }
    }

    private function writeHeaderBlock(H2DataAccessor $acc) : void
    {
        foreach($this->headerBlocks as $blk) {
            HeaderBlock::pack($blk, $acc);
        }
    }

    public function addHeaderBlock(HeaderBlock $blk) : void
    {
        $this->headerBlocks[] = $blk;
    }
}