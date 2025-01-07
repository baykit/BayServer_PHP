<?php

namespace baykit\bayserver\docker\http\h2;


use baykit\bayserver\BayLog;
use baykit\bayserver\docker\http\h1\command\CmdContent;
use baykit\bayserver\docker\http\h1\command\CmdHeader;
use baykit\bayserver\docker\http\h2\command\CmdData;
use baykit\bayserver\docker\http\h2\command\CmdGoAway;
use baykit\bayserver\docker\http\h2\command\CmdHeaders;
use baykit\bayserver\docker\http\h2\command\CmdPing;
use baykit\bayserver\docker\http\h2\command\CmdPreface;
use baykit\bayserver\docker\http\h2\command\CmdPriority;
use baykit\bayserver\docker\http\h2\command\CmdRstStream;
use baykit\bayserver\docker\http\h2\command\CmdSettings;
use baykit\bayserver\docker\http\h2\command\CmdWindowUpdate;
use baykit\bayserver\protocol\CommandUnPacker;
use baykit\bayserver\protocol\Packet;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Sink;

class H2CommandUnPacker extends CommandUnPacker {

    public $cmdHandler;

    public function __construct(H2CommandHandler $handler)
    {
        $this->cmdHandler = $handler;
        $this->reset();
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
    }


    ////////////////////////////////////////////////////////////////////////////////
    // Implements CommandUnPacker
    ////////////////////////////////////////////////////////////////////////////////

    public function packetReceived(Packet $pkt): int
    {
        BayLog::debug("h2: read packet typ=%d strmid=%d len=%d flags=%s",
                        $pkt->type, $pkt->streamId, $pkt->dataLen(), $pkt->flags);

        switch ($pkt->type) {
            case H2Type::PREFACE:
                $cmd = new CmdPreface($pkt->streamId, $pkt->flags);
                break;

            case H2Type::HEADERS:
                $cmd = new CmdHeaders($pkt->streamId, $pkt->flags);
                break;

            case H2Type::PRIORITY:
                $cmd = new CmdPriority($pkt->streamId, $pkt->flags);
                break;

            case H2Type::SETTINGS:
                $cmd = new CmdSettings($pkt->streamId, $pkt->flags);
                break;

            case H2Type::WINDOW_UPDATE:
                $cmd = new CmdWindowUpdate($pkt->streamId, $pkt->flags);
                break;

            case H2Type::DATA:
                $cmd = new CmdData($pkt->streamId, $pkt->flags);
                break;

            case H2Type::GOAWAY:
                $cmd = new CmdGoAway($pkt->streamId, $pkt->flags);
                break;

            case H2Type::PING:
                $cmd = new CmdPing($pkt->streamId, $pkt->flags);
                break;

            case H2Type::RST_STREAM:
                $cmd = new CmdRstStream($pkt->streamId);
                break;

            default:
                throw new ProtocolException("Received packet with unknown type: %s", $pkt);
        }

        $cmd->unpack($pkt);
        return $cmd->handle($this->cmdHandler);
    }
}