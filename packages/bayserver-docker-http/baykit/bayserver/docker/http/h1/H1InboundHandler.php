<?php

namespace baykit\bayserver\docker\http\h1;


use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\agent\UpgradeException;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\common\InboundHandler;
use baykit\bayserver\common\InboundShip;
use baykit\bayserver\docker\http\h1\command\CmdContent;
use baykit\bayserver\docker\http\h1\command\CmdEndContent;
use baykit\bayserver\docker\http\h1\command\CmdHeader;
use baykit\bayserver\docker\http\HtpDocker;
use baykit\bayserver\HttpException;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\protocol\ProtocolHandlerStore;
use baykit\bayserver\Symbol;
use baykit\bayserver\tour\ReqContentHandlerUtil;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\ClassUtil;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\HttpUtil;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\URLEncoder;


class H1InboundHandler implements H1Handler, InboundHandler {

    const STATE_READ_HEADER = 1;
    const STATE_READ_CONTENT = 2;
    const STATE_FINISHED = 3;

    const FIXED_REQ_ID = 1;

    public H1ProtocolHandler $protocolHandler;
    public bool $headerRead;
    public ?string $httpProtocol;

    public int $state;
    public int $curReqId = 1;
    public ?Tour $curTour = null;
    public int $curTourId = -1;

    public function __construct()
    {
        $this->resetState();
    }

    public function init(H1ProtocolHandler $hnd): void
    {
        $this->protocolHandler = $hnd;
    }

    public function __toString(): string
    {
        return ClassUtil::localName(get_class($this));
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        $this->resetState();

        $this->headerRead = false;
        $this->httpProtocol = null;
        $this->curReqId = 1;
        $this->curTour = null;
        $this->curTourId = 0;
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Implements InboundHandler
    ////////////////////////////////////////////////////////////////////////////////

    public function sendResHeaders(Tour $tur): void
    {
        // determine Connection header value
        if ($tur->req->headers->getConnection() != Headers::CONNECTION_KEEP_ALIVE)
            # If client doesn't support "Keep-Alive", set "Close"
            $res_con = "Close";
        else {
            $res_con = "Keep-Alive";
            # Client supports "Keep-Alive"
            if ($tur->res->headers->getConnection() != Headers::CONNECTION_KEEP_ALIVE) {
                # If tours doesn't need "Keep-Alive"
                if ($tur->res->headers->contentLength() == -1) {
                    # If content-length not specified
                    if ($tur->res->headers->contentType() !== null &&
                        StringUtil::startsWith("text/", $tur->res->headers->contentType())) {
                        # If content is text, connection must be closed
                        $res_con = "Close";
                    }
                }
            }
        }

        $tur->res->headers->set(Headers::CONNECTION, $res_con);

        if (BayServer::$harbor->traceHeader()) {
            BayLog::info("%s resStatus:%d", $tur, $tur->res->headers->status);
            foreach ($tur->res->headers->names() as $name) {
                foreach ($tur->res->headers->values($name) as $value) {
                    BayLog::info("%s resHeader:%s=%s", $tur, $name, $value);
                }
            }
        }

        $cmd = CmdHeader::newResHeader($tur->res->headers, $tur->req->protocol);
        $this->protocolHandler->post($cmd);
    }

    public function sendResContent(Tour $tur, string $bytes, int $ofs, int $len, ?callable $callback): void
    {
        $cmd = new CmdContent($bytes, $ofs, $len);
        $this->protocolHandler->post($cmd, $callback);
    }

    public function sendEndTour(Tour $tur, bool $keepAlive, ?callable $callback): void
    {
        $sip = $this->ship();
        BayLog::trace("%s sendEndTour: tur=%s keep=%s", $sip, $tur, $keepAlive);

        # Send dummy end request command
        $cmd = new CmdEndContent();

        $sid = $sip->shipId;
        $ensure_func = function() use ($callback, $keepAlive, $sip, $sid) {
            if ($keepAlive) {
                $sip->keeping = true;
                $sip->resumeRead($sid);
            }
            else
                $sip->postClose();
        };


        try {
            $this->protocolHandler->post($cmd, function () use ($ensure_func, $callback, $sip, $tur) {
                BayLog::debug("%s call back of end content command: tur=%s", $sip, $tur);
                $ensure_func();
                $callback();
            });
        }
        catch(IOException $e) {
            $ensure_func();
            throw $e;
        }

    }

    public function sendReqProtocolError(ProtocolException $e): bool
    {
        if ($this->curTour === null)
            $tur = $this->ship->getErrorTour();
        else
            $tur = $this->curTour;

        $tur->res->sendError(Tour::TOUR_ID_NOCHECK, HttpStatus::BAD_REQUEST, $e->getMessage(), $e);
        return true;
    }

    function onProtocolError(ProtocolException $e): bool
    {
        BayLog::debug("onProtocolError: %s", $e);
        if($this->curTour == null)
            $tur = $this->ship()->getErrorTour();
        else
            $tur = $this->curTour;

        $tur->res->sendError(Tour::TOUR_ID_NOCHECK, HttpStatus::BAD_REQUEST, $e->getMessage(), $e);
        return true;
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Implements H1CommandHandler
    ////////////////////////////////////////////////////////////////////////////////
    public function handleHeader(CmdHeader $cmd): int
    {
        $sip = $this->ship();
        BayLog::debug("%s handleHeader: method=%s uri=%s proto=%s", $sip, $cmd->method, $cmd->uri, $cmd->version);

        if ($this->state == self::STATE_FINISHED)
            $this->changeState(self::STATE_READ_HEADER);

        if ($this->state != self::STATE_READ_HEADER || $this->curTour !== null) {
            $msg = "Header command not expected: state=" . $this->state . " curTour=" . $this->curTour;
            BayLog::error($msg);
            $this->resetState();
            throw new ProtocolException($msg);
        }

        // check HTTP2
        $protocol = strtoupper($cmd->version);
        if ($protocol == "HTTP/2.0") {
            $port = $sip->portDocker();
            if ($port->supportH2) {
                $sip->portDocker()->returnProtocolHandler($sip->agentId, $this->protocolHandler);
                $newHnd = ProtocolHandlerStore::getStore(HtpDocker::H2_PROTO_NAME, true, $sip->agentId)->rent();
                $sip->setProtocolHandler($newHnd);
                throw new UpgradeException();
            } else {
                throw new ProtocolException(
                    BayMessage::get(Symbol::HTP_UNSUPPORTED_PROTOCOL, $protocol));
            }
        }

        $tur = $sip->getTour($this->curReqId);
        if ($tur === null) {
            BayLog::error(BayMessage::get(Symbol::INT_NO_MORE_TOURS));
            $tur = $sip->getTour($this->curReqId, true);
            $tur->res->sendError(Tour::TOUR_ID_NOCHECK, HttpStatus::SERVICE_UNAVAILABLE, "No available tours");
            return NextSocketAction::CONTINUE;
        }

        $this->curTour = $tur;
        $this->curTourId = $tur->tourId;
        $this->curReqId++;  // issue new request id

        $sip->keeping = false;

        $this->httpProtocol = $protocol;

        $tur->req->uri = URLEncoder::encodeTilde($cmd->uri);
        $tur->req->method = strtoupper($cmd->method);
        $tur->req->protocol = $protocol;

        if (!($tur->req->protocol == "HTTP/1.1"
            || $tur->req->protocol == "HTTP/1.0"
            || $tur->req->protocol == "HTTP/0.9")) {

            throw new ProtocolException(
                BayMessage::get(Symbol::HTP_UNSUPPORTED_PROTOCOL, $tur->req->protocol));
        }

        foreach ($cmd->headers as $nv) {
            $tur->req->headers->add($nv[0], $nv[1]);
        }

        $reqContLen = $tur->req->headers->contentLength();
        BayLog::debug("%s read header method=%s protocol=%s uri=%s contlen=%d",
            $sip, $tur->req->method, $tur->req->protocol, $tur->req->uri, $tur->req->headers->contentLength());

        if (BayServer::$harbor->traceHeader()) {
            foreach ($cmd->headers as $item) {
                BayLog::info($tur . " h1: reqHeader: " . $item[0] . "=" . $item[1]);
            }
        }

        if ($reqContLen > 0) {
            $tur->req->setLimit($reqContLen);
        }

        try {

            $this->startTour($tur);

            if ($reqContLen <= 0) {
                $this->endReqContent($this->curTourId, $tur);
                return NextSocketAction::SUSPEND; // end reading
            } else {
                $this->changeState(self::STATE_READ_CONTENT);
                return NextSocketAction::CONTINUE;
            }

        } catch (HttpException $e) {
            BayLog::trace($this . " Http error occurred: " . $e);
            if ($reqContLen <= 0) {
                // no post data
                $tur->res->sendHttpException(Tour::TOUR_ID_NOCHECK, $e);

                $this->resetState(); // next: read empty stdin command
                return NextSocketAction::CONTINUE;
            } else {
                // Delay send
                BayLog::trace($this . " error sending is delayed");
                $this->changeState(self::STATE_READ_CONTENT);
                $tur->error = $e;
                $tur->req->setContentHandler(ReqContentHandlerUtil::$devNull);
                return NextSocketAction::CONTINUE;
            }
        }
    }

    public function handleContent(CmdContent $cmd): int
    {
        BayLog::debug("%s handleContent: len=%s", $this->ship(), $cmd->len);

        if ($this->state != self::STATE_READ_CONTENT) {
            $s = $this->state;
            $this->resetState();
            throw new ProtocolException("Content command not expected: state=" . $s);
        }

        $tur = $this->curTour;
        $tourId = $this->curTourId;

        try {
            $sid = $this->ship()->shipId;
            $success = $tur->req->postReqContent(
                $tourId,
                $cmd->buf,
                $cmd->start,
                $cmd->len,
                function ($len, $resume) use ($sid) {
                    if ($resume)
                        $this->ship()->resume($sid);
                });

            if ($tur->req->bytesPosted == $tur->req->bytesLimit) {
                if($tur->error !== null){
                    // Error has occurred on header completed
                    BayLog::debug("%s Delay send error", $tur);
                    throw $tur->error;
                }
                else {
                    $this->endReqContent($tourId, $tur);
                    return NextSocketAction::SUSPEND; // end reading
                }
            }
        }
        catch (HttpException $e) {
            $tur->req->abort();
            $tur->res->sendHttpException($tourId, $e);
            $this->resetState();
            return NextSocketAction::WRITE;
        }

        if(!$success)
            return NextSocketAction::SUSPEND;
        else
            return NextSocketAction::CONTINUE;
    }

    public function handleEndContent(CmdEndContent $cmdEndContent): int
    {
        throw new Sink();
    }

    public function reqFinished(): bool
    {
        return $this->state == self::STATE_FINISHED;
    }

    private function ship(): InboundShip
    {
        return $this->protocolHandler->ship;
    }

    private function endReqContent(int $chkTurId, Tour $tur) : void
    {
        $tur->req->endReqContent($chkTurId);
        $this->resetState();
    }

    private function startTour(Tour $tur) : void
    {
        $secure = $this->ship()->portDocker()->secure();
        HttpUtil::parseHostPort($tur, $secure ? 443 : 80);
        HttpUtil::parseAuthrization($tur);

        $rd = $this->ship()->rudder;

        // Get remote address
        $clientAdr = $tur->req->headers->get(Headers::X_FORWARDED_FOR);
        if ($clientAdr != null) {
            $tur->req->remoteAddress = $clientAdr;
            $tur->req->remotePort = -1;
        }
        else {
            try {
                $name = stream_socket_get_name($rd->key(), true);
                BayLog::debug("name: %s", $name);
                if ($name) {
                    list($host, $port) = explode(":", $name);
                    $tur->req->remoteAddress = $host;
                    $tur->req->remotePort = intval($port);
                }
            }
            catch(\Exception $e) {
                BayLog::error_e($e);
                // Unix domain socket
                $tur->req->remoteAddress = null;
                $tur->req->remotePort = -1;
            }
        }
        $tur->req->remoteHostFunc = function () use ($tur) {
            if($tur->req->remoteAddress)
                return HttpUtil::resolveHost($tur->req->remoteAddress);
            else
                return null;
        };

        $name = stream_socket_get_name($rd->key(), false);
        list($host, $port) = explode(":", $name);
        $tur->req->serverAddress = $host;
        $tur->req->serverPort = $tur->req->reqPort;
        $tur->req->serverName = $tur->req->reqHost;
        $tur->isSecure = $secure;

        $tur->go();
    }

    private function changeState(int $newState) : void
    {
        $this->state = $newState;
    }

    private function resetState() : void
    {
        $this->headerRead = false;
        $this->changeState(self::STATE_FINISHED);
        $this->curTour = null;
    }

}