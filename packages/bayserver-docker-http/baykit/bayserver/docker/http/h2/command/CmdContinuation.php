<?php

namespace baykit\bayserver\docker\http\h2\command;

use baykit\bayserver\docker\http\h2\H2Command;
use baykit\bayserver\docker\http\h2\H2DataAccessor;
use baykit\bayserver\docker\http\h2\H2Flags;
use baykit\bayserver\docker\http\h2\H2Type;
use baykit\bayserver\docker\http\h2\HeaderBlock;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;


/**
 * HTTP/2 Continuation payload format
 *
 * +---------------------------------------------------------------+
 * |                   Header Block Fragment (*)...
 * +---------------------------------------------------------------+
 */
class CmdContinuation extends H2Command
{
    /** Pre-decoded blocks; populated on the outbound path only. */
    public $headerBlocks = [];
    /** See CmdHeaders::$rawHeaderBlock. */
    public string $rawHeaderBlock = "";

    public function __construct()
    {
        parent::__construct(H2Type::CONTINUATION);
    }

    public function reset() : void
    {
        $this->headerBlocks = [];
        $this->rawHeaderBlock = "";
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);
        $this->rawHeaderBlock = substr($pkt->buf, $pkt->headerLen, $pkt->dataLen());
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newH2DataAccessor();
        $this->writeHeaderBlock($acc);
        parent::pack($pkt);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleContinuation($this);
    }

    private function readHeaderBlock(H2DataAccessor $acc, int $len) : void
    {
        while ($acc->pos < $len) {
            $blk = HeaderBlock::unpack($acc);
            $this->headerBlocks[] = $blk;
        }
    }

    private function writeHeaderBlock(H2DataAccessor $acc) : void
    {
        foreach ($this->headerBlocks as $blk) {
            HeaderBlock::pack($blk, $acc);
        }
    }

    public function addHeaderBlock(HeaderBlock $blk) : void
    {
        $this->headerBlocks[] = $blk;
    }
}
