<?php

namespace baykit\bayserver\docker\fcgi;


use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\docker\fcgi\FcgPacket;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\PacketUnPacker;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Sink;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\SimpleBuffer;

/**
 * Packet unmarshall logic for FCGI
 */
class FcgPacketUnPacker extends PacketUnPacker {

    private $headerBuf;
    private $dataBuf;

    const STATE_READ_PREAMBLE = 1;
    const STATE_READ_CONTENT = 2;
    const STATE_READ_PADDING = 3;
    const STATE_END = 4;

    private $state = self::STATE_READ_PREAMBLE;

    private $version;
    private $type;
    private $reqId;
    private $length;
    private $padding;
    private $paddingReadBytes;

    private $cmdUnpacker;
    private $pktStore;
    private $contLen;
    private $readBytes;

    public function __construct(PacketStore $pktStore, FcgCommandUnPacker $cmdUnpacker)
    {
        $this->cmdUnpacker = $cmdUnpacker;
        $this->pktStore = $pktStore;
        $this->headerBuf = new SimpleBuffer();
        $this->dataBuf = new SimpleBuffer();
        $this->reset();
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        $this->state = self::STATE_READ_PREAMBLE;
        $this->version = 0;
        $this->type = null;
        $this->reqId = 0;
        $this->length = 0;
        $this->padding = 0;
        $this->paddingReadBytes = 0;
        $this->contLen = 0;
        $this->readBytes = 0;
        $this->headerBuf->reset();
        $this->dataBuf->reset();
    }


    ////////////////////////////////////////////////////////////////////////////////
    // Implements PacketUnPacker
    ////////////////////////////////////////////////////////////////////////////////

    public function bytesReceived(string $buf): int
    {
        $nextSuspend = false;
        $nextWrite = false;
        $pos = 0;

        while ( $pos < strlen($buf)) {
            while ($this->state != self::STATE_END and $pos < strlen($buf)) {
                switch ($this->state) {
                    case self::STATE_READ_PREAMBLE: {
                        // preamble read mode
                        $len = FcgPacket::PREAMBLE_SIZE - $this->headerBuf->len;
                        if (strlen($buf) - $pos < $len)
                            $len = strlen($buf) - $pos;
                        $this->headerBuf->put($buf, $pos, $len);
                        $pos += $len;

                        if ($this->headerBuf->len == FcgPacket::PREAMBLE_SIZE) {
                            $this->headerReadDone();
                            if ($this->length == 0) {
                                if ($this->padding == 0)
                                    $this->changeState(self::STATE_END);
                                else
                                    $this->changeState(self::STATE_READ_PADDING);
                            } else {
                                $this->changeState(self::STATE_READ_CONTENT);
                            }
                        }
                        break;
                    }
                    case self::STATE_READ_CONTENT: {
                        // content read mode
                        $len = $this->length - $this->dataBuf->len;
                        if ($len > strlen($buf) - $pos) {
                            $len = strlen($buf) - $pos;
                        }
                        if ($len > 0) {
                            $this->dataBuf->put($buf, $pos, $len);
                            $pos += $len;

                            if ($this->dataBuf->len == $this->length) {
                                if ($this->padding == 0)
                                    $this->changeState(self::STATE_END);
                                else
                                    $this->changeState(self::STATE_READ_PADDING);
                            }
                        }
                        break;
                    }
                    case self::STATE_READ_PADDING: {
                        // padding read mode
                        $len = $this->padding - $this->paddingReadBytes;
                        if ($len > strlen($buf) - $pos) {
                            $len = strlen($buf) - $pos;
                        }

                        $pos += $len;

                        if ($len > 0) {
                            $this->paddingReadBytes += $len;
                            if ($this->paddingReadBytes == $this->padding) {
                                $this->changeState(self::STATE_END);
                            }
                        }
                        break;
                    }
                    default:
                        throw new \Exception("Illegal State");
                }
            }

            if ($this->state == self::STATE_END) {
                $pkt = $this->pktStore->rent($this->type);
                $pkt->reqId = $this->reqId;
                $pkt->newHeaderAccessor()->putBytes($this->headerBuf->bytes(), 0, $this->headerBuf->len);
                $pkt->newDataAccessor()->putBytes($this->dataBuf->bytes(), 0, $this->dataBuf->len);

                try {
                    $state = $this->cmdUnpacker->packetReceived($pkt);
                }
                finally {
                    $this->pktStore->Return($pkt);
                }
                $this->reset();

                switch($state) {
                    case NextSocketAction::SUSPEND:
                        $nextSuspend = true;
                        break;

                    case NextSocketAction::CONTINUE:
                        break;

                    case NextSocketAction::WRITE:
                        $nextWrite = true;
                        break;

                    case NextSocketAction::CLOSE:
                        return $state;

                    default:
                        throw new Sink();
                }
            }
        }

        if($nextWrite)
            return NextSocketAction::WRITE;
        else if($nextSuspend)
            return NextSocketAction::SUSPEND;
        else
            return NextSocketAction::CONTINUE;
     }

    ////////////////////////////////////////////////////////////////////////////////
    // Private methods
    ////////////////////////////////////////////////////////////////////////////////

    private function changeState(int $newState) : void
    {
        $this->state = $newState;
    }

    private function headerReadDone() : void
    {
        $pre = $this->headerBuf->bytes();
        $this->version = ord($pre[0]);
        $this->type = ord($pre[1]);
        $this->reqId = ord($pre[2]) << 8 | ord($pre[3]);
        $this->length = ord($pre[4]) << 8 | ord($pre[5]);
        $this->padding = ord($pre[6]);
        $reserved = ord($pre[7]);
        BayLog::debug("fcg Read packet header: version=%s type=%s reqId=%d length=%d padding=%d",
                        $this->version, $this->type, $this->reqId, $this->length, $this->padding);
    }
}