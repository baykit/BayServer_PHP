<?php

namespace baykit\bayserver\docker\http\h2;


use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\PacketUnPacker;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\SimpleBuffer;

class H2PacketUnPacker_FrameHeaderItem {
    public $start;
    public $len;
    public $pos; // relative reading position

    public function __construct(int $start, int $len)
    {
        $this->start = $start;
        $this->len = $len;
        $this->pos = 0;
    }

    public function get(SimpleBuffer $buf, int $index) : int
    {
        return ord($buf->buf[$this->start + $index]) & 0xFF;
    }
}



class H2PacketUnPacker extends PacketUnPacker {

    const STATE_READ_LENGTH = 1;
    const STATE_READ_TYPE = 2;
    const STATE_READ_FLAGS = 3;
    const STATE_READ_STREAM_IDENTIFIER = 4;
    const STATE_READ_FLAME_PAYLOAD = 5;
    const STATE_END = 6;

    const FRAME_LEN_LENGTH = 3;
    const FRAME_LEN_TYPE = 1;
    const FRAME_LEN_FLAGS = 1;
    const FRAME_LEN_STREAM_IDENTIFIER = 4;

    const FLAGS_END_STREAM = 0x1;
    const FLAGS_END_HEADERS = 0x4;
    const FLAGS_PADDED = 0x8;
    const FLAGS_PRIORITY = 0x20;

    const CONNECTION_PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

    public $state = self::STATE_READ_LENGTH;
    public $tmpBuf;
    public $item;  // FrameItem
    public $prefaceRead;
    public $type; // H2Type
    public $payloadLen;
    public $flags;
    public $streamId;

    private $cmdUnpacker;
    private $pktStore;
    private $serverMode;

    private $contLen;
    private $readBytes;
    private $pos;


    public function __construct(H2CommandUnPacker $cmdUnpacker, PacketStore $pktStore, bool $serverMode)
    {
        $this->cmdUnpacker = $cmdUnpacker;
        $this->pktStore = $pktStore;
        $this->serverMode = $serverMode;
        $this->tmpBuf = new SimpleBuffer();
        $this->reset();
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        $this->resetState();
        $this->prefaceRead = false;
    }


    public function resetState() : void
    {
        $this->changeState(self::STATE_READ_LENGTH);
        $this->item = new H2PacketUnPacker_FrameHeaderItem(0, self::FRAME_LEN_LENGTH);
        $this->contLen = 0;
        $this->readBytes = 0;
        $this->tmpBuf = new SimpleBuffer();
        $this->type = null;
        $this->flags = 0;
        $this->streamId = 0;
        $this->payloadLen = 0;
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Implements PacketUnPacker
    ////////////////////////////////////////////////////////////////////////////////

    public function bytesReceived(string $buf): int
    {
        $suspend = false;
        $this->pos = 0;

        if($this->serverMode && !$this->prefaceRead) {
            $len = strlen(self::CONNECTION_PREFACE) - $this->tmpBuf->len;
            if($len > strlen($buf))
                $len = strlen($buf);

            $this->tmpBuf->put($buf, 0, $len);
            $this->pos += $len;

            if($this->tmpBuf->len == strlen(self::CONNECTION_PREFACE)) {
                for($i = 0; $i < $this->tmpBuf->len; $i++) {
                    if(self::CONNECTION_PREFACE[$i] != $this->tmpBuf->bytes()[$i])
                        throw new ProtocolException("Invalid connection preface");
                }
                $pkt = $this->pktStore->rent(H2Type::PREFACE);
                $pkt->newDataAccessor()->putBytes($this->tmpBuf->buf, 0, $this->tmpBuf->len);
                $nstat = $this->cmdUnpacker->packetReceived($pkt);
                $this->pktStore->Return($pkt);
                if($nstat != NextSocketAction::CONTINUE)
                    return $nstat;

                BayLog::debug("Connection preface OK");
                $this->prefaceRead = true;
                $this->tmpBuf->reset();
            }
        }
        
        while ($this->state != self::STATE_END && $this->pos < strlen($buf)) {
            switch ($this->state) {
                case self::STATE_READ_LENGTH:
                    if($this->readHeaderItem($buf)) {
                        $this->payloadLen = (($this->item->get($this->tmpBuf, 0) & 0xFF) << 16 |
                                             ($this->item->get($this->tmpBuf, 1) & 0xFF) << 8 |
                                             ($this->item->get($this->tmpBuf, 2) & 0xFF));
                        $this->item = new H2PacketUnPacker_FrameHeaderItem($this->tmpBuf->len, self::FRAME_LEN_TYPE);
                        $this->changeState(self::STATE_READ_TYPE);
                    }
                    break;

                case self::STATE_READ_TYPE:
                    if($this->readHeaderItem($buf)) {
                        $this->type = $this->item->get($this->tmpBuf, 0);
                        $this->item = new H2PacketUnPacker_FrameHeaderItem($this->tmpBuf->len, self::FRAME_LEN_FLAGS);
                        $this->changeState(self::STATE_READ_FLAGS);
                    }
                    break;

                case self::STATE_READ_FLAGS:
                    if($this->readHeaderItem($buf)) {
                        $this->flags = $this->item->get($this->tmpBuf, 0);
                        $this->item = new H2PacketUnPacker_FrameHeaderItem($this->tmpBuf->len, self::FRAME_LEN_STREAM_IDENTIFIER);
                        $this->changeState(self::STATE_READ_STREAM_IDENTIFIER);
                    }
                    break;

                case self::STATE_READ_STREAM_IDENTIFIER:
                    if($this->readHeaderItem($buf)) {
                        $this->streamId = (($this->item->get($this->tmpBuf, 0) & 0x7F) << 24) |
                                           ($this->item->get($this->tmpBuf, 1) << 16) |
                                           ($this->item->get($this->tmpBuf, 2) << 8) |
                                           $this->item->get($this->tmpBuf, 3);
                        $this->item = new H2PacketUnPacker_FrameHeaderItem($this->tmpBuf->len, $this->payloadLen);
                        $this->changeState(self::STATE_READ_FLAME_PAYLOAD);
                    }
                    break;

                case self::STATE_READ_FLAME_PAYLOAD:
                    if($this->readHeaderItem($buf)) {
                        $this->changeState(self::STATE_END);
                    }
                    break;

                default:
                    throw new \Exception();
            }


            if ($this->state == self::STATE_END) {
                $pkt = $this->pktStore->rent($this->type);
                $pkt->streamId = $this->streamId;
                $pkt->flags = new H2Flags($this->flags);
                $pkt->newHeaderAccessor()->putBytes($this->tmpBuf->buf, 0, H2Packet::FRAME_HEADER_LEN);
                $pkt->newDataAccessor()->putBytes($this->tmpBuf->buf, H2Packet::FRAME_HEADER_LEN, $this->tmpBuf->len - H2Packet::FRAME_HEADER_LEN);
                try {
                    $nxtAct = $this->cmdUnpacker->packetReceived($pkt);
                    //BayServer.debug("H2 NextAction=" + nxtAct + " sz=" + tmpBuf.length() + " remain=" + buf.hasRemaining());
                }
                finally {
                    $this->pktStore->Return($pkt);
                    $this->resetState();
                }
                if($nxtAct == NextSocketAction::SUSPEND) {
                    $suspend = true;
                }
                else if($nxtAct != NextSocketAction::CONTINUE)
                    return $nxtAct;
            }
        }

        if($suspend)
            return NextSocketAction::SUSPEND;
        else
            return NextSocketAction::CONTINUE;

    }

    ////////////////////////////////////////////////////////////////////////////////
    // Private methods
    ////////////////////////////////////////////////////////////////////////////////

    private function readHeaderItem(string $buf) : bool
    {
        $len = $this->item->len - $this->item->pos;
        if(strlen($buf) - $this->pos < $len)
            $len = strlen($buf) - $this->pos;
        $this->tmpBuf->put($buf, $this->pos, $len);
        $this->pos += $len;
        $this->item->pos += $len;

        return $this->item->pos == $this->item->len;
    }

    private function changeState(int $newState) : void
    {
        $this->state = $newState;
    }
}