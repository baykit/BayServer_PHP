<?php

namespace baykit\bayserver\docker\ajp;


use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\PacketUnPacker;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\SimpleBuffer;

/**
 * AJP Protocol
 * https://tomcat.apache.org/connectors-doc/ajp/ajpv13a.html
 *
 */
class AjpPacketUnPacker extends PacketUnPacker {

    private $preambleBuf;
    private $bodyBuf;

    const STATE_READ_PREAMBLE = 1;
    const STATE_READ_BODY = 2;
    const STATE_END = 3;

    private $state = self::STATE_READ_PREAMBLE;

    private $pktStore;
    private $cmdUnpacker;
    private $bodyLen;
    private $readBytes;
    private $type;
    private $toServer;
    private $needData;


    public function __construct(PacketStore $pktStore, AjpCommandUnPacker $cmdUnpacker)
    {
        $this->cmdUnpacker = $cmdUnpacker;
        $this->pktStore = $pktStore;
        $this->preambleBuf = new SimpleBuffer();
        $this->bodyBuf = new SimpleBuffer();
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        $this->state = self::STATE_READ_PREAMBLE;
        $this->bodyLen = 0;
        $this->readBytes = 0;
        $this->needData = false;
        $this->preambleBuf->reset();
        $this->bodyBuf->reset();
    }


    ////////////////////////////////////////////////////////////////////////////////
    // Implements PacketUnPacker
    ////////////////////////////////////////////////////////////////////////////////

    public function bytesReceived(string $buf): int
    {
        $suspend = false;
        $pos = 0;

        while ( $pos < strlen($buf)) {
            if ($this->state == self::STATE_READ_PREAMBLE) {
                $len = AjpPacket::PREAMBLE_SIZE - $this->preambleBuf->len;
                if (strlen($buf) - $pos < $len)
                    $len = strlen($buf) - $pos;

                $this->preambleBuf->put($buf, $pos, $len);
                $pos += $len;

                if ($this->preambleBuf->len == AjpPacket::PREAMBLE_SIZE) {
                    $this->preambleRead();
                    $this->changeState(self::STATE_READ_BODY);
                }
            }

            if ($this->state == self::STATE_READ_BODY) {
                $len = $this->bodyLen - $this->bodyBuf->len;
                if ($len > strlen($buf) - $pos)
                    $len = strlen($buf) - $pos;

                $this->bodyBuf->put($buf, $pos, $len);
                $pos += $len;

                if ($this->bodyBuf->len == $this->bodyLen) {
                    $this->bodyRead();
                    $this->changeState(self::STATE_END);
                }
            }

            if ($this->state == self::STATE_END) {
                //BayLog.trace("ajp: parse end: preamblelen=" + preambleBuf.length() + " bodyLen=" + bodyBuf.length() + " type=" + type);
                $pkt = $this->pktStore->rent($this->type);
                $pkt->toServer = $this->toServer;
                $pkt->newAjpHeaderAccessor()->putBytes($this->preambleBuf->buf, 0, $this->preambleBuf->len);
                $pkt->newAjpDataAccessor()->putBytes($this->bodyBuf->buf, 0, $this->bodyBuf->len);

                try {
                    $nextSocketAction = $this->cmdUnpacker->packetReceived($pkt);
                }
                finally {
                    $this->pktStore->Return($pkt);
                }

                $this->reset();
                $this->needData = $this->cmdUnpacker->needData();

                if($nextSocketAction == NextSocketAction::SUSPEND) {
                    $suspend = true;
                }
                else if($nextSocketAction != NextSocketAction::CONTINUE)
                    return $nextSocketAction;
            }
        }

        BayLog::debug("ajp next read");
        if($suspend)
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

    private function preambleRead() : void
    {
        $data = $this->preambleBuf->buf;

        if (ord($data[0]) == 0x12 && ord($data[1]) == 0x34)
            $this->toServer = true;
        else if ($data[0] == 'A' && $data[1] == 'B')
            $this->toServer = false;
        else
            throw new ProtocolException("Must be start with 0x1234 or 'AB'");

        $this->bodyLen = ((ord($data[2]) << 8) | (ord($data[3]) & 0xff)) & 0xffff;
        BayLog::trace("ajp: read packet preamble: bodyLen=%d", $this->bodyLen);
    }

    private function bodyRead() : void
    {
        if($this->needData)
            $this->type = AjpType::DATA;
        else
            $this->type = ord($this->bodyBuf->buf[0]) & 0xf;
    }


}