<?php

namespace baykit\bayserver\docker\http\h1;


use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\PacketUnPacker;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\util\SimpleBuffer;

/**
 * Read HTTP header
 *
 *   HTTP/1.x has no packet format. So we make HTTP header and content pretend to be packet
 *
 *   From RFC2616
 *   generic-message : start-line
 *                     (message-header CRLF)*
 *                     CRLF
 *                     [message-body]
 *
 *
 */
class H1PacketUnpacker extends PacketUnPacker {

    const STATE_READ_HEADERS = 1;
    const STATE_READ_CONTENT = 2;
    const STATE_END = 3;

    const MAX_LINE_LEN = 8192;

    public $state = self::STATE_READ_HEADERS;

    public $cmdUnpacker;
    public $pktStore;
    public $tmpBuf;

    public function __construct(H1CommandUnPacker $cmdUnpacker, PacketStore $pktStore)
    {
        $this->cmdUnpacker = $cmdUnpacker;
        $this->pktStore = $pktStore;
        $this->tmpBuf = new SimpleBuffer();
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        $this->resetState();
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Implements PacketUnpacker
    ////////////////////////////////////////////////////////////////////////////////


    public function bytesReceived(string $buf): int
    {
        if($this->state == self::STATE_END) {
            $this->reset();
            throw new \Exception();
        }

        $pos = 0;
        $lineLen = 0;
        if($this->state == self::STATE_READ_HEADERS) {

            while ($pos < strlen($buf)) {
                $b = $buf[$pos];
                $this->tmpBuf->putByte($b);
                $pos++;
                if ($b == "\r")
                    continue;
                elseif ($b == "\n") {
                    if($lineLen == 0) {
                        $pkt = $this->pktStore->rent(H1Type::HEADER);
                        $pkt->newDataAccessor()->putBytes($this->tmpBuf->bytes(), 0, $this->tmpBuf->len);
                        try {
                            $nextAct = $this->cmdUnpacker->packetReceived($pkt);
                        }
                        finally {
                            $this->pktStore->Return($pkt);
                        }

                        $breakLoop = false;
                        switch($nextAct) {
                            case NextSocketAction::CONTINUE:
                                if($this->cmdUnpacker->reqFinished())
                                    $this->changeState(self::STATE_END);
                                else
                                    $this->changeState(self::STATE_READ_CONTENT);
                                $breakLoop = true;
                                break;
                            case NextSocketAction::CLOSE:
                                // Maybe error
                                $this->resetState();
                                return $nextAct;
                        }

                        if($breakLoop)
                            break;
                    }
                    $lineLen = 0;
                }
                else {
                    $lineLen++;
                }

                if($lineLen >= self::MAX_LINE_LEN) {
                    throw new ProtocolException("Http/1 Line is too long");
                }
            }
        }

        $suspend = false;
        if($this->state == self::STATE_READ_CONTENT) {
            while($pos < strlen($buf)) {
                $pkt = $this->pktStore->rent(H1Type::CONTENT);
                $len = strlen($buf) - $pos;
                if($len > H1Packet::MAX_DATA_LEN)
                    $len = H1Packet::MAX_DATA_LEN;

                $pkt->newDataAccessor()->putBytes($buf, $pos, $len);
                $pos += $len;

                try {
                    $nextAct = $this->cmdUnpacker->packetReceived($pkt);
                }
                finally {
                    $this->pktStore->Return($pkt);
                }

                switch($nextAct) {
                    case NextSocketAction::CONTINUE:
                        if($this->cmdUnpacker->reqFinished())
                            $this->changeState(self::STATE_END);
                        break;
                    case NextSocketAction::SUSPEND:
                        $suspend = true;
                        break;
                    case NextSocketAction::CLOSE:
                        $this->resetState();
                        return $nextAct;
                }
            }
        }

        if($this->state == self::STATE_END)
            $this->resetState();

        if($suspend) {
            BayLog::debug("H1 read suspend");
            return NextSocketAction::SUSPEND;
        }
        else
            return NextSocketAction::CONTINUE;
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Other methods
    ////////////////////////////////////////////////////////////////////////////////

    private function changeState(int $newState) : void
    {
        $this->state = $newState;
    }

    private function resetState() : void
    {
        $this->changeState(self::STATE_READ_HEADERS);
        $this->tmpBuf->reset();
    }
}