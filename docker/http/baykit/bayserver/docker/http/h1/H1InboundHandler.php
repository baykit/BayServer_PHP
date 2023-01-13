<?php

namespace baykit\bayserver\docker\http\h1;


use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\agent\UpgradeException;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\docker\base\InboundHandler;
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
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\HttpUtil;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\URLEncoder;


class H1InboundHandler extends H1ProtocolHandler implements InboundHandler {

    const STATE_READ_HEADER = 1;
    const STATE_READ_CONTENT = 2;
    const STATE_FINISHED = 3;

    const FIXED_REQ_ID = 1;

    public $headerRead;
    public $httpProtocol;

    public $state;
    public $curReqId = 1;
    public $curTour;
    public $curTourId;

    public function __construct(PacketStore $pktStore)
    {
        parent::__construct($pktStore, true);
        $this->resetState();
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        parent::reset();
        $this->curReqId = 1;
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

        if (BayServer::$harbor->traceHeader) {
            BayLog::info("%s resStatus:%d", $tur, $tur->res->headers->status);
            foreach ($tur->res->headers->names() as $name) {
                foreach ($tur->res->headers->values($name) as $value) {
                    BayLog::info("%s resHeader:%s=%s", $tur, $name, $value);
                }
            }
        }

        $cmd = CmdHeader::newResHeader($tur->res->headers, $tur->req->protocol);
        $this->commandPacker->post($this->ship, $cmd);
    }

    public function sendResContent(Tour $tur, string $bytes, int $ofs, int $len, ?callable $callback): void
    {
        $cmd = new CmdContent($bytes, $ofs, $len);
        $this->commandPacker->post($this->ship, $cmd, $callback);
    }

    public function sendEndTour(Tour $tur, bool $keepAlive, callable $callback): void
    {
        BayLog::trace("%s sendEndTour: tur=%s keep=%s", $this->ship, $tur, $keepAlive);

        # Send dummy end request command
        $cmd = new CmdEndContent();

        $ensure_func = function() use ($callback, $keepAlive) {
            if ($keepAlive && !$this->ship->postman->isZombie())
                $this->ship->keeping = true;
            else
                $this->commandPacker->end($this->ship);
        };


        try {
            $this->commandPacker->post($this->ship, $cmd, function () use ($ensure_func, $callback, $tur) {
                BayLog::debug("%s call back of end content command: tur=%s", $this->ship, $tur);
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

    ////////////////////////////////////////////////////////////////////////////////
    // Implements H1CommandHandler
    ////////////////////////////////////////////////////////////////////////////////
    public function handleHeader(CmdHeader $cmd): int
    {
        $sip = $this->ship;
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
                $sip->portDocker()->returnProtocolHandler($sip->agent, $this);
                $newHnd = ProtocolHandlerStore::getStore(HtpDocker::H2_PROTO_NAME, true, $sip->agent->agentId)->rent();
                $sip->setProtocolHandler($newHnd);
                throw new UpgradeException();
            } else {
                throw new ProtocolException(
                    BayMessage::get(Symbol::HTP_UNSUPPORTED_PROTOCOL, $protocol));
            }
        }

        $tur = $sip->getTour($this->curReqId);
        $this->curTour = $tur;
        $this->curTourId = $tur->tourId;
        $this->curReqId++;  // issue new request id

        if ($tur === null) {
            BayLog::error(BayMessage::get(Symbol::INT_NO_MORE_TOURS));
            $tur = $sip->getTour($this->curReqId, true);
            $tur->res->sendError(Tour::TOUR_ID_NOCHECK, HttpStatus::SERVICE_UNAVAILABLE, "No available tours");
            return NextSocketAction::CONTINUE;
        }

        $sip->keeping = false;

        $this->httpProtocol = $protocol;

        $tur->req->uri = URLEncoder::encodeTilde($cmd->uri);
        $tur->req->method = strtoupper($cmd->method);
        $tur->req->protocol = $protocol;

        if (!($tur->req->protocol == "HTTP/1.1")
            || ($tur->req->protocol == "HTTP/1.0")
            || ($tur->req->protocol == "HTTP/0.9")) {

            throw new ProtocolException(
                BayMessage::get(Symbol::HTP_UNSUPPORTED_PROTOCOL, $tur->req->protocol));
        }

        foreach ($cmd->headers as $nv) {
            $tur->req->headers->add($nv[0], $nv[1]);
        }

        $reqContLen = $tur->req->headers->contentLength();
        BayLog::debug("%s read header method=%s protocol=%s uri=%s contlen=%d",
            $sip, $tur->req->method, $tur->req->protocol, $tur->req->uri, $tur->req->headers->contentLength());

        if (BayServer::$harbor->traceHeader) {
            foreach ($cmd->headers as $item) {
                BayLog::info($tur . " h1: reqHeader: " . $item[0] . "=" . $item[1]);
            }
        }

        if ($reqContLen > 0) {
            $sid = $sip->shipId;
            $tur->req->setConsumeListener($reqContLen, function ($len, $resume) use ($sid, $sip) {
                if ($resume)
                    $sip->resume($sid);
            });
        }

        try {

            $this->startTour($tur);

            if ($reqContLen <= 0) {
                $this->endReqContent($this->curTourId, $tur);
                return NextSocketAction::CONTINUE;
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
        BayLog::debug("%s handleContent: len=%s", $this->ship, $cmd->len);

        if ($this->state != self::STATE_READ_CONTENT) {
            $s = $this->state;
            $this->resetState();
            throw new ProtocolException("Content command not expected: state=" . $s);
        }

        $tur = $this->curTour;
        $tourId = $this->curTourId;
        $success = $tur->req->postContent($tourId, $cmd->buf, $cmd->start, $cmd->len);

        if ($tur->req->bytesPosted == $tur->req->bytesLimit) {
            if($tur->error !== null){
                // Error has occurred on header completed
                $tur->res->sendHttpException($tourId, $tur->error);
                $this->resetState();
                return NextSocketAction::WRITE;
            }
            else {
                try {
                    $this->endReqContent($tourId, $tur);
                    return NextSocketAction::CONTINUE;
                } catch (HttpException $e) {
                    $tur->res->sendHttpException($tourId, $e);
                    $this->resetState();
                    return NextSocketAction::WRITE;
                }
            }
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

    private function endReqContent(int $chkTurId, Tour $tur) : void
    {
        $tur->req->endContent($chkTurId);
        $this->resetState();
    }

    private function startTour(Tour $tur) : void
    {
        $secure = $this->ship->portDocker()->secure();
        HttpUtil::parseHostPort($tur, $secure ? 443 : 80);
        HttpUtil::parseAuthrization($tur);

        $skt = $this->ship->socket;

        // Get remote address
        $clientAdr = $tur->req->headers->get(Headers::X_FORWARDED_FOR);
        if ($clientAdr != null) {
            $tur->req->remoteAddress = $clientAdr;
            $tur->req->remotePort = -1;
        }
        else {
            try {
                $name = stream_socket_get_name($skt, true);
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

        $name = stream_socket_get_name($skt, false);
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