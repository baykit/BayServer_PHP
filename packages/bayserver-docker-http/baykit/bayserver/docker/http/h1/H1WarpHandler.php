<?php

namespace baykit\bayserver\docker\http\h1;


use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\docker\http\h1\command\CmdContent;
use baykit\bayserver\docker\http\h1\command\CmdEndContent;
use baykit\bayserver\docker\http\h1\command\CmdHeader;
use baykit\bayserver\docker\warp\WarpData;
use baykit\bayserver\docker\warp\WarpHandler;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\DataConsumeListener;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\StringUtil;


class H1WarpHandler extends H1ProtocolHandler implements WarpHandler {

    const STATE_READ_HEADER = 1;
    const STATE_READ_CONTENT = 2;
    const STATE_FINISHED = 3;

    const FIXED_WARP_ID = 1;

    public $headerRead;
    public $httpProtocol;

    public $state;
    public $curReqId = 1;
    public $curTour;
    public $curTourId;

    public function __construct(PacketStore $pktStore)
    {
        parent::__construct($pktStore, false);
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

    //////////////////////////////////////////
    // Implements H1CommandHandler
    //////////////////////////////////////////
    public function handleHeader(CmdHeader $cmd): int
    {
        $tur = $this->ship->getTour(self::FIXED_WARP_ID);
        $wdat = WarpData::get($tur);
        BayLog::debug("%s handleHeader status=%d", $wdat, $cmd->status);
        $this->ship->keeping = false;
        if ($this->state == self::STATE_FINISHED)
            $this->changeState(self::STATE_READ_HEADER);

        if ($this->state != self::STATE_READ_HEADER)
            throw new ProtocolException("Header command not expected");

        if(BayServer::$harbor->traceHeader) {
            BayLog::info("%s warp_http: resStatus: %d", $wdat, $cmd->status);
        }

        foreach( $cmd->headers as $nv) {
            $tur->res->headers->add($nv[0], $nv[1]);
            if(BayServer::$harbor->traceHeader) {
                BayLog::info("%s warp_http: resHeader: %s=%s", $wdat, $nv[0], $nv[1]);
            }
        }

        $tur->res->headers->status = $cmd->status;
        $resContLen = $tur->res->headers->contentLength();
        $tur->res->sendHeaders(Tour::TOUR_ID_NOCHECK);
        //BayLog.debug(wdat + " contLen in header=" + resContLen);
        if ($resContLen == 0 || $cmd->status == HttpStatus::NOT_MODIFIED) {
            $this->endResContent($tur);
        } else {
            $this->changeState(self::STATE_READ_CONTENT);
            $sid = $this->ship->id();
            $tur->res->setConsumeListener(function ($len, $resume) use ($sid) {
                if($resume) {
                    $this->wsip->resume($sid);
                }
            });
        }
        return NextSocketAction::CONTINUE;
    }

    public function handleContent(CmdContent $cmd): int
    {
        $tur = $this->ship->getTour(self::FIXED_WARP_ID);
        $wdat = WarpData::get($tur);
        BayLog::debug("%s handleContent len=%d posted=%d contLen=%d",
            $wdat, $cmd->len, $tur->res->bytesPosted, $tur->res->bytesLimit);

        if ($this->state != self::STATE_READ_CONTENT)
            throw new ProtocolException("Content command not expected");


        $available = $tur->res->sendContent(Tour::TOUR_ID_NOCHECK, $cmd->buf, $cmd->start, $cmd->len);
        if ($tur->res->bytesPosted == $tur->res->bytesLimit) {
            $this->endResContent($tur);
            return NextSocketAction::CONTINUE;
        }
        else if(!$available) {
            return NextSocketAction::SUSPEND;
        }
        else {
            return NextSocketAction::CONTINUE;
        }
    }

    public function handleEndContent(CmdEndContent $cmdEndContent): int
    {
        throw new Sink();
    }

    public function reqFinished(): bool
    {
        return $this->state == self::STATE_FINISHED;
    }

    //////////////////////////////////////////
    // Implements WarpHandler
    //////////////////////////////////////////

    public function nextWarpId(): int
    {
        return self::FIXED_WARP_ID;
    }

    public function newWarpData(int $warpId): WarpData
    {
        return new WarpData($this->ship, $warpId);
    }

    public function postWarpHeaders(Tour $tur): void
    {
        $town = $tur->town;

        //BayServer.debug(this + " construct header");
        $townPath = $town->name;
        if (!StringUtil::endsWith($townPath, "/"))
            $townPath .= "/";

        $newUri = $this->ship->docker->warpBase . substr($tur->req->uri, strlen($townPath));

        $cmd = CmdHeader::newReqHeader(
            $tur->req->method,
            $newUri,
            "HTTP/1.1");

        foreach($tur->req->headers->names() as $name) {
            foreach ($tur->req->headers->values($name) as $value) {
                $cmd->addHeader($name, $value);
            }
        }

        if($tur->req->headers->contains(Headers::X_FORWARDED_FOR))
            $cmd->setHeader(Headers::X_FORWARDED_FOR, $tur->req->headers->get(Headers::X_FORWARDED_FOR));
        else
            $cmd->setHeader(Headers::X_FORWARDED_FOR, $tur->req->remoteAddress);

        if($tur->req->headers->contains(Headers::X_FORWARDED_PROTO))
            $cmd->setHeader(Headers::X_FORWARDED_PROTO, $tur->req->headers->get(Headers::X_FORWARDED_PROTO));
        else
            $cmd->setHeader(Headers::X_FORWARDED_PROTO, $tur->isSecure ? "https" : "http");

        if($tur->req->headers->contains(Headers::X_FORWARDED_PORT))
            $cmd->setHeader(Headers::X_FORWARDED_PORT, $tur->req->headers->get(Headers::X_FORWARDED_PORT));
        else
            $cmd->setHeader(Headers::X_FORWARDED_PORT, strval($tur->req->serverPort));

        if($tur->req->headers->contains(Headers::X_FORWARDED_HOST))
            $cmd->setHeader(Headers::X_FORWARDED_HOST, $tur->req->headers->get(Headers::X_FORWARDED_HOST));
        else
            $cmd->setHeader(Headers::X_FORWARDED_HOST, $tur->req->headers->get(Headers::HOST));

        $cmd->setHeader(Headers::HOST, $this->ship->docker->host . ":" . $this->ship->docker->port);
        $cmd->setHeader(Headers::CONNECTION, "Keep-Alive");

        if(BayServer::$harbor->traceHeader) {
            foreach($cmd->headers as $kv)
                BayLog::info("%s warp_http reqHdr: %s=%s", $tur, $kv[0], $kv[1]);
        }

        $this->ship->post($cmd);
    }

    public function postWarpContents(Tour $tur, string $buf, int $start, int $len, callable $lis): void
    {
        $cmd = new CmdContent($buf, $start, $len);
        $this->ship->post($cmd, $lis);
     }

    public function postWarpEnd(Tour $tur): void
    {
        $callback = function() {
            $this->ship->agent->nonBlockingHandler->askToRead($this->ship->socket);
        };

        $this->ship->post(null, $callback);
    }

    public function verifyProtocol(string $protocol): void
    {
    }

    //////////////////////////////////////////
    // Other methods
    //////////////////////////////////////////

    private function resetState() : void
    {
        $this->changeState(self::STATE_FINISHED);
    }

    private function endResContent(Tour $tur) : void
    {
        $this->ship->endWarpTour($tur);
        $tur->res->endContent(Tour::TOUR_ID_NOCHECK);
        $this->resetState();
        $this->ship->keeping = true;
    }

    private function changeState(int $newState) : void
    {
        $this->state = $newState;
    }

}