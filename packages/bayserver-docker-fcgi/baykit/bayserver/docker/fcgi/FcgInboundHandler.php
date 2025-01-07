<?php

namespace baykit\bayserver\docker\fcgi;

use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\common\InboundHandler;
use baykit\bayserver\common\InboundShip;
use baykit\bayserver\docker\fcgi\command\CmdBeginRequest;
use baykit\bayserver\docker\fcgi\command\CmdEndRequest;
use baykit\bayserver\docker\fcgi\command\CmdParams;
use baykit\bayserver\docker\fcgi\command\CmdStdErr;
use baykit\bayserver\docker\fcgi\command\CmdStdIn;
use baykit\bayserver\docker\fcgi\command\CmdStdOut;
use baykit\bayserver\HttpException;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Symbol;
use baykit\bayserver\tour\ReqContentHandlerUtil;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\CGIUtil;
use baykit\bayserver\util\ClassUtil;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\HttpUtil;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\SimpleBuffer;
use baykit\bayserver\util\StringUtil;


class FcgInboundHandler implements InboundHandler, FcgHandler
{

    const HDR_HTTP_CONNECTION = "HTTP_CONNECTION";

    const STATE_BEGIN_REQUEST = 1;
    const STATE_READ_PARAMS = 2;
    const STATE_READ_STDIN = 3;

    private FcgProtocolHandler $protocolHandler;
    private int $state;

    private $env = [];
    private int $reqId = 0;
    private bool $reqKeepAlive = false;

    public function __construct()
    {
        $this->resetState();
    }

    public function init(FcgProtocolHandler $hnd): void
    {
        $this->protocolHandler = $hnd;
    }

    public function __toString(): string
    {
        return ClassUtil::localName(get_class($this));
    }

    ///////////////////////////////////////////
    // Implements Reusable
    ///////////////////////////////////////////

    public function reset() : void
    {
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
        $sip = $this->ship();
        BayLog::debug($sip . " PH:sendHeaders: tur=" . $tur);

        $scode = $tur->res->headers->status;
        $status = $scode . " " . HttpStatus::description($scode);
        $tur->res->headers->set(Headers::STATUS, $status);

        if(BayServer::$harbor->traceHeader()) {
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
        $this->protocolHandler->post($cmd);
    }

    public function sendResContent(Tour $tur, string $bytes, int $ofs, int $len, ?callable $callback): void
    {
        $cmd = new CmdStdOut($tur->req->key, $bytes, $ofs, $len);
        $this->protocolHandler->post($cmd, $callback);
    }

    public function sendEndTour(Tour $tur, bool $keepAlive, ?callable $callback): void
    {
        $sip = $this->ship();
        BayLog::debug("%s PH:endTour: tur=%s keep=%s", $sip, $tur, $keepAlive);

        // Send empty stdout command
        $cmd = new CmdStdOut($tur->req->key);
        $this->protocolHandler->post($cmd);

        // Send end request command
        $cmd = new CmdEndRequest($tur->req->key);
        $ensureFunc = function () use ($keepAlive, $sip) {
            if(!$keepAlive)
                $sip->postClose();
        };

        try {
            $this->protocolHandler->post($cmd, function () use ($callback, $ensureFunc, $keepAlive, $tur, $sip) {
                BayLog::debug("%s call back in sendEndTour: tur=%s keep=%b", $sip, $tur, $keepAlive);
                $ensureFunc();
                $callback();
            });
        }
        catch(IOException $e) {
            BayLog::debug("%s post faile in sendEndTour: tur=%s keep=%b", $sip, $tur, $keepAlive);
            $ensureFunc();
            throw $e;
        }
    }

    function onProtocolError(ProtocolException $e): bool
    {
        BayLog::debug_e($e);
        $ibShip = $this->ship();
        $tur = $ibShip->getErrorTour();
        $tur->res->sendError(Tour::TOUR_ID_NOCHECK, HttpStatus::BAD_REQUEST, $e->getMessage(), $e);
        return true;
    }


    ///////////////////////////////////////////
    // Implements FcgCommandHandler
    ///////////////////////////////////////////

    public function handleBeginRequest(CmdBeginRequest $cmd): int
    {
        $sip = $this->ship();
        BayLog::debug($sip . " handleBeginRequest reqId=" . $cmd->reqId . " keep=" . $cmd->keepConn);

        if($this->state != self::STATE_BEGIN_REQUEST)
            throw new ProtocolException("fcgi: Invalid command: " . $cmd->type . " state=" . $this->state);

        $this->checkReqId($cmd->reqId);

        $tur = $sip->getTour($cmd->reqId);
        if($tur == null) {
            BayLog::error(BayMessage::get(Symbol::INT_NO_MORE_TOURS));
            $tur = $sip->getTour($cmd->reqId, true);
            $tur->res->sendError(Tour::TOUR_ID_NOCHECK, HttpStatus::SERVICE_UNAVAILABLE, "No available tours");
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
        $sip = $this->ship();
        if (BayLog::isDebugMode())
            BayLog::debug($sip . " handleParams reqId=" . $cmd->reqId . " nParams=" . count($cmd->params));

        if($this->state != self::STATE_READ_PARAMS)
            throw new ProtocolException("fcgi: Invalid command: " . $cmd->type . " state=" . $this->state);

        $this->checkReqId($cmd->reqId);

        $tur = $sip->getTour($cmd->reqId);

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
            if (BayServer::$harbor->traceHeader()) {
                foreach($tur->req->headers->names() as $name) {
                    foreach ($tur->req->headers->values($name) as $value) {
                        BayLog::info("%s  reqHeader: %s=%s", $tur, $name, $value);
                    }
                }
            }

            if($reqContLen > 0) {
                $tur->req->setLimit($reqContLen);
            }

            $this->changeState(self::STATE_READ_STDIN);
            try {
                $this->startTour($tur);

                return NextSocketAction::CONTINUE;

            } catch (HttpException $e) {
                BayLog::debug("%s Http error occurred: %s", $this, $e);
                if($reqContLen <= 0) {
                    // no post data
                    $tur->res->sendHttpException(Tour::TOUR_ID_NOCHECK, $e);

                    $this->changeState(self::STATE_READ_STDIN); // next: read empty stdin command
                }
                else {
                    // Delay send
                    $this->changeState(self::STATE_READ_STDIN);
                    $tur->error = $e;
                    $tur->req->setContentHandler(ReqContentHandlerUtil::$devNull);
                }
                return NextSocketAction::CONTINUE;
            }
        }
        else {
            if (BayServer::$harbor->traceHeader()) {
                BayLog::info("%s Read FcgiParam", $tur);
            }
            foreach ($cmd->params as $nv) {
                $name = $nv[0];
                $value = $nv[1];
                if (BayServer::$harbor->traceHeader()) {
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
        $sip = $this->ship();
        BayLog::debug("%s handleStdIn reqId=%d len=%d", $sip, $cmd->reqId, $cmd->length);

        if($this->state != self::STATE_READ_STDIN)
            throw new ProtocolException("fcgi: Invalid FCGI command: " . $cmd->type . " state=" . $this->state);

        $this->checkReqId($cmd->reqId);

        $tur = $sip->getTour($cmd->reqId);
        try {
            if($cmd->length == 0) {
                // request content completed

                if($tur->error != null){
                    // Error has occurred on header completed
                    BayLog::debug("%s Delay send error", $tur);
                    throw $tur->error;
                }
                else {
                    $this->endReqContent(Tour::TOUR_ID_NOCHECK, $tur);
                    return NextSocketAction::CONTINUE;
                }
            }
            else {
                $sid = $sip->shipId;
                $success = $tur->req->postReqContent(
                                            Tour::TOUR_ID_NOCHECK,
                                            $cmd->data,
                                            $cmd->start,
                                            $cmd->length,
                                            function($len, $resume) use ($sip, $sid) {
                                                if ($resume)
                                                    $sip->resumeRead($sid);
                                            });

                if (!$success)
                    return NextSocketAction::SUSPEND;
                else
                    return NextSocketAction::CONTINUE;
            }
        }
        catch (HttpException $e) {
            $tur->req->abort();
            $tur->res->sendHttpException(Tour::TOUR_ID_NOCHECK, $e);
            $this->resetState();
            return NextSocketAction::WRITE;
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
        $sip = $this->ship();
        if($receivedId == FcgPacket::FCGI_NULL_REQUEST_ID)
            throw new ProtocolException("Invalid request id: " . $receivedId);

        if($this->reqId == FcgPacket::FCGI_NULL_REQUEST_ID)
            $reqId = $receivedId;

        if($reqId != $receivedId) {
            BayLog::error($sip . " invalid request id: received=" . $receivedId . " reqId=" . $reqId);
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
        $tur->req->endReqContent($checkId);
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

    private function ship(): InboundShip
    {
        return $this->protocolHandler->ship;
    }
}