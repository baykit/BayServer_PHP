<?php

namespace baykit\bayserver\docker\ajp;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\docker\ajp\command\CmdData;
use baykit\bayserver\docker\ajp\command\CmdEndResponse;
use baykit\bayserver\docker\ajp\command\CmdForwardRequest;
use baykit\bayserver\docker\ajp\command\CmdGetBodyChunk;
use baykit\bayserver\docker\ajp\command\CmdSendBodyChunk;
use baykit\bayserver\docker\ajp\command\CmdSendHeaders;
use baykit\bayserver\docker\ajp\command\CmdShutdown;
use baykit\bayserver\docker\base\InboundHandler;
use baykit\bayserver\HttpException;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Symbol;
use baykit\bayserver\tour\ReqContentHandlerUtil;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\HttpUtil;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\ArrayUtil;


class AjpInboundHandler extends AjpProtocolHandler implements InboundHandler
{

    const STATE_READ_FORWARD_REQUEST = 1;
    const STATE_READ_DATA = 2;

    const DUMMY_KEY = 1;

    public $curTourId;
    public $reqCommand;

    public $state;
    public $keeping;

    public function __construct($pktStore)
    {
        parent::__construct($pktStore, true);
        $this->resetState();
    }

    ///////////////////////////////////////////
    // Implements Reusable
    ///////////////////////////////////////////

    public function reset() : void
    {
        parent::reset();
        $this->resetState();
        $this->reqCommand = null;
        $this->keeping = false;
        $this->curTourId = 0;
    }

    ///////////////////////////////////////////
    // Implements InboundHandler
    ///////////////////////////////////////////

    public function sendReqProtocolError(ProtocolException $e): bool
    {
        $tur = $this->ship->getErrorTour();
        $tur->res->sendError(Tour::TOUR_ID_NOCHECK, HttpStatus::BAD_REQUEST, $e->getMessage(), $e);
        return true;
    }

    public function sendResHeaders(Tour $tur): void
    {
        $chunked = false;
        $cmd = new CmdSendHeaders();
        foreach($tur->res->headers->names() as $name) {
            foreach($tur->res->headers->values($name) as $value) {
                $cmd->addHeader($name, $value);
            }
        }
        $cmd->setStatus($tur->res->headers->status);
        $this->commandPacker->post($this->ship, $cmd);
    }

    public function sendResContent(Tour $tur, string $bytes, int $ofs, int $len, ?callable $callback): void
    {
        $cmd = new CmdSendBodyChunk($bytes, $ofs, $len);
        $this->commandPacker->post($this->ship, $cmd, $callback);
    }

    public function sendEndTour(Tour $tur, bool $keepAlive, callable $callback): void
    {
        BayLog::debug("%s endTour: tur=%s keep=%s", $this->ship, $tur, $keepAlive);
        $cmd = new CmdEndResponse();
        $cmd->reuse = $keepAlive;

        $ensureFunc = function () use ($keepAlive) {
            if (!$keepAlive)
                $this->commandPacker->end($this->ship);
        };

        try {
            $this->commandPacker->post($this->ship, $cmd, function () use ($callback, $ensureFunc, $keepAlive, $tur) {
                BayLog::debug("%s call back in sendEndTour: tur=%s keep=%s", $this->ship, $tur, $keepAlive);
                $ensureFunc();
                $callback();
            });
        }
        catch(IOException $e) {
            BayLog::debug("%s post failed in sendEndTour: tur=%s keep=%s", $this->ship, $tur, $keepAlive);
            $ensureFunc();
            throw $e;
        }
    }

    ///////////////////////////////////////////
    // Implements AjpCommandHandler
    ///////////////////////////////////////////

    public function handleForwardRequest(CmdForwardRequest $cmd): int
    {
        BayLog::debug("%s handleForwardRequest method=%s uri=%s", $this->ship, $cmd->method, $cmd->reqUri);

        if($this->state != self::STATE_READ_FORWARD_REQUEST)
            throw new ProtocolException("Invalid AJP command: " . $cmd->type);

        $this->keeping = false;
        $this->reqCommand = $cmd;
        $tur = $this->ship->getTour(self::DUMMY_KEY);
        if($tur == null) {
            BayLog::error(BayMessage::get(Symbol::INT_NO_MORE_TOURS));
            $tur = $this->ship->getTour(self::DUMMY_KEY, true);
            $tur->res->sendError(Tour::TOUR_ID_NOCHECK, HttpStatus::SERVICE_UNAVAILABLE, "No available tours");
            $tur->res->endContent(Tour::TOUR_ID_NOCHECK);
            $this->ship->agent.shutdown();
            return NextSocketAction::CONTINUE;
        }

        $curTourId = $tur->id();
        $tur->req->uri = $cmd->reqUri;
        $tur->req->protocol = $cmd->protocol;
        $tur->req->method = $cmd->method;
        $cmd->headers->copyTo($tur->req->headers);

        $queryString = ArrayUtil::get("?query_string", $cmd->attributes);
        if (StringUtil::isSet($queryString))
            $tur->req->uri .= "?" . $queryString;

        BayLog::debug( "%s read header method=%s protocol=%s uri=%s contlen=%d",
            $tur, $tur->req->method, $tur->req->protocol, $tur->req->uri, $tur->req->headers->contentLength());
        if (BayServer::$harbor->traceHeader) {
            foreach ($cmd->headers->names() as $name) {
                foreach($cmd->headers->values($name) as $value) {
                    BayLog::info("%s header: %s=%s", $tur, $name, $value);
                }
            }
        }

        $reqContLen = $cmd->headers->contentLength();

        if($reqContLen > 0) {
            $sid = $this->ship->shipId;
            $tur->req->setConsumeListener($reqContLen, function ($len, $resume) use ($sid) {
                if ($resume)
                    $this->ship->resume($sid);
            });
        }

        try {
            $this->startTour($tur);

            if($reqContLen <= 0) {
                $this->endReqContent($tur);
            }
            else {
                $this->changeState(self::STATE_READ_DATA);
            }
            return NextSocketAction::CONTINUE;

        } catch (HttpException $e) {
            if($reqContLen <= 0) {
                $tur->res->sendHttpException(Tour::TOUR_ID_NOCHECK, $e);
               //$tur->zombie = true;
                $this->resetState();
                return NextSocketAction::WRITE;
            }
            else {
                // Delay send
                $this->changeState(self::STATE_READ_DATA);
                $tur->error = $e;
                $tur->req->setContentHandler(ReqContentHandlerUtil::$devNull);
                return NextSocketAction::CONTINUE;
            }
        }
    }

    public function handleData(CmdData $cmd): int
    {
        BayLog::debug("%s handleData len=%s", $this->ship, $cmd->length);

        if($this->state != self::STATE_READ_DATA)
            throw new ProtocolException("Invalid AJP command: " . $cmd->type . " state=" . $this->state);

        $tur = $this->ship->getTour(self::DUMMY_KEY);
        $success = $tur->req->postContent(Tour::TOUR_ID_NOCHECK, $cmd->data, $cmd->start, $cmd->length);

        if($tur->req->bytesPosted == $tur->req->bytesLimit) {
            // request content completed

            if($tur->error != null){
                // Error has occurred on header completed

                $tur->res->sendHttpException(Tour::TOUR_ID_NOCHECK, $tur->error);
                $this->resetState();
                return NextSocketAction::WRITE;
            }
            else {
                try {
                    $this->endReqContent(tur);
                    return NextSocketAction::CONTINUE;
                } catch (HttpException $e) {
                    $tur->res->sendHttpException(Tour::TOUR_ID_NOCHECK, $e);
                    $this->resetState();
                    return NextSocketAction::WRITE;
                }
            }
        }
        else {
            $bch = new CmdGetBodyChunk();
            $bch->reqLen = $tur->req->bytesLimit - $tur->req->bytesPosted;
            if($bch->reqLen > AjpPacket::MAX_DATA_LEN) {
                $bch->reqLen = AjpPacket::MAX_DATA_LEN;
            }
            $this->commandPacker->post($this->ship, $bch);

            if(!$success)
                return NextSocketAction::SUSPEND;
            else
                return NextSocketAction::CONTINUE;
        }
    }

    public function handleEndResponse(CmdEndResponse $cmd): int
    {
        throw new ProtocolException("Invalid AJP command: " . $cmd->type);
    }



    public function handleSendBodyChunk(CmdSendBodyChunk $cmd): int
    {
        throw new ProtocolException("Invalid AJP command: " . $cmd->type);
    }

    public function handleSendHeaders(CmdSendHeaders $cmd): int
    {
        throw new ProtocolException("Invalid AJP command: " . $cmd->type);
    }

    public function handleShutdown(CmdShutdown $cmd): int
    {
        BayLog::debug($this->ship . " handleShutdown");
        GrandAgent::shutdownAll();
        return NextSocketAction::CLOSE;
    }

    public function handleGetBodyChunk(CmdGetBodyChunk $cmd): int
    {
        throw new ProtocolException("Invalid AJP command: " . $cmd->type);
    }

    public function needData(): bool
    {
        return $this->state == self::STATE_READ_DATA;
    }

    ///////////////////////////////////////////
    // Private methods
    ///////////////////////////////////////////

    private function resetState() : void
    {
        $this->changeState(self::STATE_READ_FORWARD_REQUEST);
    }

    private function changeState(int $newState) : void
    {
        $this->state = $newState;
    }

    private function endReqContent(Tour $tur) : void
    {
        $tur->req->endContent(Tour::TOUR_ID_NOCHECK);
        $this->resetState();
    }

    private function startTour(Tour $tur) : void
    {
        HttpUtil::parseHostPort($tur, $this->reqCommand->isSsl ? 443 : 80);
        HttpUtil::parseAuthrization($tur);

        $tur->req->remotePort = -1;
        $tur->req->remoteAddress = $this->reqCommand->remoteAddr;
        $tur->req->remoteHostFunc = function () { return $this->reqCommand->remoteHost; };

        $tur->req->serverAddress = stream_socket_get_name($this->ship->socket, false);
        $tur->req->serverPort = $this->reqCommand->serverPort;
        $tur->req->serverName = $this->reqCommand->serverName;
        $tur->isSecure = $this->reqCommand->isSsl;

        $tur->go();
    }
}