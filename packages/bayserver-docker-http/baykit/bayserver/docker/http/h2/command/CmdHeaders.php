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
use baykit\bayserver\protocol\ProtocolException;



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
 * |                   Header Block Fragment (*)...
 * +---------------------------------------------------------------+
 * |                           Padding (*)...
 * +---------------------------------------------------------------+
 */
class CmdHeaders extends H2Command
{
    public $padLength = 0;
    public $excluded = false;
    public $streamDependency = 0;
    public $weight = 0;
    /** Pre-decoded blocks; populated on the outbound path only. */
    public $headerBlocks = [];
    /**
     * Raw header-block fragment bytes captured at unpack time. HPACK
     * decoding is deferred to H2InboundHandler so that a header block
     * split across HEADERS + CONTINUATION can be reassembled before
     * decoding (a partial slice is not valid HPACK input).
     */
    public string $rawHeaderBlock = "";

    public function __construct()
    {
        parent::__construct(H2Type::HEADERS);
    }

    public function reset() : void
    {
        $this->padLength = 0;
        $this->excluded = false;
        $this->streamDependency = 0;
        $this->weight = 0;
        $this->headerBlocks = [];
        $this->rawHeaderBlock = "";
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
            // RFC 7540 § 5.3.1: a stream MUST NOT depend on itself.
            // Mirrors
            if ($this->streamDependency === $this->streamId)
                throw new ProtocolException("HEADERS stream depends on itself: {$this->streamId}");
        }
        // RFC 7540 § 6.2: padding length must leave at least one
        // octet of payload. Mirrors: read up to
        // dataLen - padLength bytes for header blocks, leaving the
        // pad bytes untouched.
        $headerBlockEnd = $pkt->dataLen() - $this->padLength;
        if ($headerBlockEnd < $acc->pos)
            throw new ProtocolException("HEADERS pad length exceeds payload: pad={$this->padLength}");
        $this->rawHeaderBlock = substr($pkt->buf,
            $pkt->headerLen + $acc->pos,
            $headerBlockEnd - $acc->pos);
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
        // RFC 7541 § 4.2: dynamic-table size updates must appear at
        // the very start of a header block. Once we see any other
        // representation, any later size update is a decoding error.
        // Mirrors
        $seenNonSizeUpdate = false;
        while($acc->pos < $len) {
            $blk = HeaderBlock::unpack($acc);
            if ($blk->op === HeaderBlock::UPDATE_DYNAMIC_TABLE_SIZE) {
                if ($seenNonSizeUpdate)
                    throw new ProtocolException("Dynamic table size update must appear at the start of a header block");
            } else {
                $seenNonSizeUpdate = true;
            }
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