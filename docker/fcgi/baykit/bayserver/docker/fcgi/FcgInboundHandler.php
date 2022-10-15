<?php

namespace baykit\bayserver\docker\fcgi;

use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\docker\base\InboundHandler;
use baykit\bayserver\docker\fcgi\command\CmdBeginRequest;
use baykit\bayserver\docker\fcgi\command\CmdEndRequest;
use baykit\bayserver\docker\fcgi\command\CmdParams;
use baykit\bayserver\docker\fcgi\command\CmdStdErr;
use baykit\bayserver\docker\fcgi\command\CmdStdIn;
use baykit\bayserver\docker\fcgi\command\CmdStdOut;
use baykit\bayserver\docker\fcgi\FcgPacket;
use baykit\bayserver\docker\fcgi\FcgProtocolHandler;
use baykit\bayserver\HttpException;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Symbol;
use baykit\bayserver\tour\ReqContentHandlerUtil;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\CGIUtil;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\HttpUtil;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\SimpleBuffer;
use baykit\bayserver\util\StringUtil;


class FcgInboundHandler extends FcgProtocolHandler implements InboundHandler
{

    const HDR_HTTP_CONNECTION = "HTTP_CONNECTION";

    const STATE_BEGIN_REQUEST = 1;
    const STATE_READ_PARAMS = 2;
    const STATE_READ_STDIN = 3;

    private $state;

    private $env = [];
    private $reqId;
    private $reqKeepAlive;

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
        $this->env = [];
        $this->resetState();
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
        BayLog::debug($this->ship . " PH:sendHeaders: tur=" . $tur);

        $scode = $tur->res->headers->status;
        $status = $scode . " " . HttpStatus::description($scode);
        $tur->res->headers->set(Headers::STATUS, $status);

        if(BayServer::$harbor->traceHeader) {
            BayLog::info($tur . " resStatus:" . $tur->res->headers->status);
            foreach($tur->res->headers->names() as $name) {
                foreach($tur->res->headers->values($name) as $value) {
                    BayLog::info("%s resHeader: %s=%s" , $tur, $name, $value);
                }
            }
        }

        $buf = new SimpleBuffer();
        HttpUtil::sendMimeHeaders($tur->res->headers, $buf);
        HttpUtil::sendNewLine($buf);
        $cmd = new CmdStdOut($tur->req->key, $buf->buf, 0, $buf->len);
        $this->commandPacker->post($this->ship, $cmd);
    }

    public function sendResContent(Tour $tur, string $bytes, int $ofs, int $len, ?callable $callback): void
    {
        $cmd = new CmdStdOut($tur->req->key, $bytes, $ofs, $len);
        $this->commandPacker->post($this->ship, $cmd, $callback);
    }

    public function sendEndTour(Tour $tur, bool $keepAlive, callable $callback): void
    {
        BayLog::debug("%s PH:endTour: tur=%s keep=%s", $this->ship, $tur, $keepAlive);

        // Send empty stdout command
        $cmd = new CmdStdOut($tur->req->key);
        $this->commandPacker->post($this->ship, $cmd);

        // Send end request command
        $cmd = new CmdEndRequest($tur->req->key);
        $ensureFunc = function () use ($keepAlive) {
            if(!$keepAlive)
                $this->commandPacker->end($this->ship);
        };

        try {
            $this->commandPacker->post($this->ship, $cmd, function () use ($callback, $ensureFunc, $keepAlive, $tur) {
                BayLog::debug("%s call back in sendEndTour: tur=%s keep=%b", $this->ship, $tur, $keepAlive);
                $ensureFunc();
                $callback();
            });
        }
        catch(IOException $e) {
            BayLog::debug("%s post faile in sendEndTour: tur=%s keep=%b", $this->ship, $tur, $keepAlive);
            $ensureFunc();
            throw $e;
        }
    }

    ///////////////////////////////////////////
    // Implements FcgCommandHandler
    ///////////////////////////////////////////

    public function handleBeginRequest(CmdBeginRequest $cmd): int
    {
        BayLog::debug($this->ship . " handleBeginRequest reqId=" . $cmd->reqId . " keep=" . $cmd->keepConn);

        if($this->state != self::STATE_BEGIN_REQUEST)
            throw new ProtocolException("fcgi: Invalid command: " . $cmd->type . " state=" . $this->state);

        $this->checkReqId($cmd->reqId);

        $tur = $this->ship->getTour($cmd->reqId);
        if($tur == null) {
            BayLog::error(BayMessage::get(Symbol::INT_NO_MORE_TOURS));
            $tur = $this->ship->getTour($cmd->reqId, true);
            $tur->res->sendError(Tour::TOUR_ID_NOCHECK, HttpStatus::SERVICE_UNAVAILABLE, "No available tours");
            $this->ship->agent->shutdown();
            return NextSocketAction::CONTINUE;
        }

        $this->changeState(self::STATE_READ_PARAMS);
        return NextSocketAction::CONTINUE;
    }

    public function handleEndRequest(CmdEndRequest $cmd): int
    {
        throw new ProtocolException("Invalid FCGI command: " . $cmd->type);
    }

    public function handleParams(CmdParams $cmd): int
    {
        if (BayLog::isDebugMode())
            BayLog::debug($this->ship . " handleParams reqId=" . $cmd->reqId . " nParams=" . count($cmd->params));

        if($this->state != self::STATE_READ_PARAMS)
            throw new ProtocolException("fcgi: Invalid command: " . $cmd->type . " state=" . $this->state);

        $this->checkReqId($cmd->reqId);

        $tur = $this->ship->getTour($cmd->reqId);

        if(count($cmd->params) == 0) {
            // Header completed

            // check keep-alive
            //  keep-alive flag of BeginRequest has high priority
            if ($this->reqKeepAlive) {
                if (!$tur->req->headers->contains(Headers::CONNECTION))
                    $tur->req->headers->set(Headers::CONNECTION, "Keep-Alive");
            }
            else {
                $tur->req->headers->set(Headers::CONNECTION, "Close");
            }

            $reqContLen = $tur->req->headers->contentLength();

            // end params
            if (BayLog::isDebugMode())
                BayLog::debug($tur . " read header method=" . $tur->req->method . " protocol=" . $tur->req->protocol . " uri=" . $tur->req->uri . " contlen=" . $reqContLen);
            if (BayServer::$harbor->traceHeader) {
                foreach($tur->req->headers->names() as $name) {
                    foreach ($tur->req->headers->values($name) as $value) {
                        BayLog::info("%s  reqHeader: %s=%s", $tur, $name, $value);
                    }
                }
            }

            if($reqContLen > 0) {
                $sid = $this->ship->shipId;
                $tur->req->setConsumeListener($reqContLen, function ($len, $resume) use ($sid) {
                    if ($resume)
                        $this->ship->resume($sid);
                });
            }

            $this->changeState(self::STATE_READ_STDIN);
            try {
                $this->startTour($tur);

                return NextSocketAction::CONTINUE;

            } catch (HttpException $e) {
                BayLog::debug($this . " Http error occurred: " . $e);
                if($this->reqContLen <= 0) {
                    // no post data
                    $tur->res->sendHttpException(Tour::TOUR_ID_NOCHECK, $e);

                    $this->changeState(self::STATE_READ_STDIN); // next: read empty stdin command
                    return NextSocketAction::CONTINUE;
                }
                else {
                    // Delay send
                    $this->changeState(self::STATE_READ_STDIN);
                    $tur->error = $e;
                    $tur->req->setContentHandler(ReqContentHandlerUtil::$devNull);
                    return NextSocketAction::CONTINUE;
                }
            }
        }
        else {
            if (BayServer::$harbor->traceHeader) {
                BayLog::info("%s Read FcgiParam", $tur);
            }
            foreach ($cmd->params as $nv) {
                $name = $nv[0];
                $value = $nv[1];
                if (BayServer::$harbor->traceHeader) {
                    BayLog::info("%s  param: %s=%s", $tur, $name, $value);
                }
                $this->env[$name] = $value;

                if (StringUtil::startsWith($name, "HTTP_")) {
                    $hname = substr($name, 5);
                    $tur->req->headers->add($hname, $value);
                }
                else if ($name == "CONTENT_TYPE") {
                    $tur->req->headers->add(Headers::CONTENT_TYPE, $value);
                }
                else if ($name == "CONTENT_LENGTH") {
                    $tur->req->headers->add(Headers::CONTENT_LENGTH, $value);
                }
                else if ($name == "HTTPS") {
                    $tur->isSecure = (strtolower($value) == "on");
                }
            }

            $tur->req->uri = $this->env["REQUEST_URI"];
            $tur->req->protocol = $this->env["SERVER_PROTOCOL"];
            $tur->req->method = $this->env["REQUEST_METHOD"];

            return NextSocketAction::CONTINUE;
        }
    }

    public function handleStdErr(CmdStdErr $cmd): int
    {
        throw new ProtocolException("Invalid FCGI command: %d", $cmd->type);
    }

    public function handleStdIn(CmdStdIn $cmd): int
    {
        BayLog::debug("%s handleStdIn reqId=%d len=%d", $this->ship, $cmd->reqId, $cmd->length);

        if($this->state != self::STATE_READ_STDIN)
            throw new ProtocolException("fcgi: Invalid FCGI command: " . $cmd->type . " state=" . $this->state);

        $this->checkReqId($cmd->reqId);

        $tur = $this->ship->getTour($cmd->reqId);
        if($cmd->length == 0) {
            // request content completed

            if($tur->error != null){
                // Error has occurred on header completed

                $tur->res->sendHttpException(Tour::TOUR_ID_NOCHECK, $tur->error);
                $this->resetState();
                return NextSocketAction::WRITE;
            }
            else {
                try {
                    $this->endReqContent(Tour::TOUR_ID_NOCHECK, $tur);
                    return NextSocketAction::CONTINUE;
                } catch (HttpException $e) {
                    $tur->res->sendHttpException(Tour::TOUR_ID_NOCHECK, $e);
                    return NextSocketAction::WRITE;
                }
            }
        }
        else {
            $success = $tur->req->postContent(Tour::TOUR_ID_NOCHECK, $cmd->data, $cmd->start, $cmd->length);
            //if($tur->reqBytesRead == contLen)
            //    endContent(tur);

            if (!$success)
                return NextSocketAction::SUSPEND;
            else
                return NextSocketAction::CONTINUE;
        }
    }

    public function handleStdOut(CmdStdOut $cmd): int
    {
        throw new ProtocolException("Invalid FCGI command: %d", $cmd->type);
    }

    ///////////////////////////////////////////
    // Private methods
    ///////////////////////////////////////////

    private function checkReqId(int $receivedId) : void
    {
        if($receivedId == FcgPacket::FCGI_NULL_REQUEST_ID)
            throw new ProtocolException("Invalid request id: " . $receivedId);

        if($this->reqId == FcgPacket::FCGI_NULL_REQUEST_ID)
            $reqId = $receivedId;

        if($reqId != $receivedId) {
            BayLog::error($this->ship . " invalid request id: received=" . $receivedId . " reqId=" . $reqId);
            throw new ProtocolException("Invalid request id: " . $receivedId);
        }
    }

    private function resetState() : void
    {
        $this->changeState(self::STATE_BEGIN_REQUEST);
    }

    private function changeState(int $newState) : void
    {
        $this->state = $newState;
    }

    private function endReqContent(int $checkId, Tour $tur) : void
    {
        $tur->req->endContent($checkId);
        $this->resetState();
    }

    private function startTour(Tour $tur) : void
    {
        HttpUtil::parseHostPort($tur, $tur->isSecure ? 443 : 80);
        HttpUtil::parseAuthrization($tur);

        try {
            $tur->req->remotePort = intval($this->env[CGIUtil::REMOTE_PORT]);
        }
        catch(\Exception $e) {
            BayLog::error($e);
        }

        $tur->req->remoteAddress = $this->env[CGIUtil::REMOTE_ADDR];
        $tur->req->remoteHostFunc = function () use ($tur) {
            return HttpUtil::resolveHost($tur->req->remoteAddress);
        };

        $tur->req->serverName = $this->env[CGIUtil::SERVER_NAME];
        $tur->req->serverAddress = $this->env[CGIUtil::SERVER_ADDR];
        try {
            $tur->req->serverPort = strval($this->env[CGIUtil::SERVER_PORT]);
        }
        catch(\Exception $e) {
            BayLog::error($e);
            $tur->req->serverPort = 80;
        }

        $tur->go();
    }


}