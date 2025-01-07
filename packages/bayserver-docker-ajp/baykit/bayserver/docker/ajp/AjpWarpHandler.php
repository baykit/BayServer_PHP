<?php

namespace baykit\bayserver\docker\ajp;


use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\common\WarpData;
use baykit\bayserver\common\WarpHandler;
use baykit\bayserver\common\WarpShip;
use baykit\bayserver\docker\ajp\command\CmdData;
use baykit\bayserver\docker\ajp\command\CmdEndResponse;
use baykit\bayserver\docker\ajp\command\CmdForwardRequest;
use baykit\bayserver\docker\ajp\command\CmdGetBodyChunk;
use baykit\bayserver\docker\ajp\command\CmdSendBodyChunk;
use baykit\bayserver\docker\ajp\command\CmdSendHeaders;
use baykit\bayserver\docker\ajp\command\CmdShutdown;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\ClassUtil;
use baykit\bayserver\util\StringUtil;

class AjpWarpHandler implements WarpHandler, AjpHandler
{
    const FIXED_WARP_ID = 1;

    const STATE_READ_HEADER = 1;
    const STATE_READ_CONTENT = 2;

    private AjpProtocolHandler $protocolHandler;
    private int $state;
    private int $contReadLen = 0;

    public function __construct()
    {
        $this->resetState();
    }

    public function init(AjpProtocolHandler $hnd): void
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
        $this->resetState();
        $this->contReadLen = 0;
    }

    ///////////////////////////////////////////
    // Implements WarpHandler
    ///////////////////////////////////////////

    public function nextWarpId(): int
    {
        return 1;
    }

    public function newWarpData(int $warpId): WarpData
    {
        return new WarpData($this->ship(), $warpId);
    }

    public function sendReqHeaders(Tour $tur): void
    {
        $this->sendForwardRequest($tur);
    }

    public function sendReqContents(Tour $tur, string $buf, int $start, int $len, ?callable $callback): void
    {
        $this->sendData($tur, $buf, $start, $len, $callback);
    }

    public function sendEndReq(Tour $tur, bool $keepAlive, ?callable $callback): void
    {
        $this->ship()->post(null, $callback);
    }

    public function verifyProtocol(string $protocol): void
    {
    }

    function onProtocolError(ProtocolException $e): bool
    {
        // TODO: Implement onProtocolError() method.
    }

    ///////////////////////////////////////////
    // Implements AjpCommandHandler
    ///////////////////////////////////////////


    public function handleData(CmdData $cmd): int
    {
        throw new ProtocolException("Invalid AJP command: " . $cmd->type);
    }

    public function handleEndResponse(CmdEndResponse $cmd): int
    {
        $wsip = $this->ship();
        BayLog::debug("%s handleEndResponse reuse=%b", $wsip, $cmd->reuse);
        $tur = $wsip->getTour(self::FIXED_WARP_ID);

        if ($this->state == self::STATE_READ_HEADER)
            $this->endResHeader($tur);

        $this->endResContent($tur, $cmd->reuse);
        if($cmd->reuse)
            return NextSocketAction::CONTINUE;
        else
            return NextSocketAction::CLOSE;
  }

    public function handleForwardRequest(CmdForwardRequest $cmd): int
    {
        throw new ProtocolException("Invalid AJP command: " . $cmd->type);
    }

    public function handleSendBodyChunk(CmdSendBodyChunk $cmd): int
    {
        $wsip = $this->ship();
        BayLog::debug("%s handleBodyChunk", $wsip);
        $tur = $wsip->getTour(self::FIXED_WARP_ID);

        if ($this->state == self::STATE_READ_HEADER) {

            $sid = $wsip->id();
            $tur->res->setConsumeListener(function ($len, $resume) use ($wsip, $sid) {
                if($resume) {
                    $wsip->resumeRead($sid);
                }
            });

            $this->endResHeader($tur);
        }

        $available = $tur->res->sendResContent($tur->tourId, $cmd->chunk, 0, $cmd->length);
        $this->contReadLen += $cmd->length;
        if($available)
            return NextSocketAction::CONTINUE;
        else
            return NextSocketAction::SUSPEND;
    }

    public function handleSendHeaders(CmdSendHeaders $cmd): int
    {
        BayLog::debug("%s handleSendHeaders", $this->ship());

        $tur = $this->ship()->getTour(self::FIXED_WARP_ID);

        if ($this->state != self::STATE_READ_HEADER)
            throw new ProtocolException("Invalid AJP command: " . $cmd->type . " state=" . $this->state);

        $wdata = WarpData::get($tur);

        if(BayServer::$harbor->traceHeader())
            BayLog::info($wdata . " recv res status: " . $cmd->status);
        $wdata->resHeaders->status = $cmd->status;
        foreach ($cmd->headers as $name => $values) {
            foreach($values as $value) {
                if (BayServer::$harbor->traceHeader())
                    BayLog::info($wdata . " recv res header: " . $name . "=" . $value);
                $wdata->resHeaders->add($name, $value);
            }
        }

        return NextSocketAction::CONTINUE;
    }

    public function handleShutdown(CmdShutdown $cmd): int
    {
        throw new ProtocolException("Invalid AJP command: " . $cmd->type);
    }

    public function handleGetBodyChunk(CmdGetBodyChunk $cmd): int
    {
        BayLog::debug("%s handleGetBodyChunk", $this->ship());
        return NextSocketAction::CONTINUE;
    }

    public function needData(): bool
    {
        return false;
    }

    ///////////////////////////////////////////
    // Private methods
    ///////////////////////////////////////////

    private function endResHeader(Tour $tur) : void
    {
        $wdat = WarpData::get($tur);
        $wdat->resHeaders->copyTo($tur->res->headers);
        $tur->res->sendResHeaders(Tour::TOUR_ID_NOCHECK);
        $this->changeState(self::STATE_READ_CONTENT);
    }

    private function endResContent(Tour $tur, bool $keep) : void
    {
        $this->ship()->endWarpTour($tur, $keep);
        $tur->res->endResContent(Tour::TOUR_ID_NOCHECK);
        $this->resetState();
    }

    private function changeState(int $newState) : void
    {
        $this->state = $newState;
    }

    private function resetState() : void
    {
        $this->changeState(self::STATE_READ_HEADER);
    }

    private function sendForwardRequest(Tour $tur) : void
    {
        BayLog::debug($tur . " construct header");
        $wsip = $this->ship();

        $cmd = new CmdForwardRequest();
        $cmd->toServer = true;
        $cmd->method = $tur->req->method;
        $cmd->protocol = $tur->req->protocol;

        $relUri = $tur->req->rewrittenURI != null ? $tur->req->rewrittenURI : $tur->req->uri;
        $twnPath = $tur->town->name;
        if(!StringUtil::endsWith($twnPath, "/"))
            $twnPath .= "/";
        $relUri = substr($relUri, strlen($twnPath));
        $reqUri =  $wsip->docker->warpBase . $relUri;

        $pos = strpos($reqUri, '?');
        if($pos !== false) {
            $cmd->reqUri = substr($reqUri, 0, $pos);
            $cmd->attributes["?query_string"] = substr($reqUri, $pos + 1);
        }
        else {
            $cmd->reqUri = $reqUri;
        }
        $cmd->remoteAddr = $tur->req->remoteAddress;
        $cmd->remoteHost = $tur->req->remoteHost();
        $cmd->serverName = $tur->req->serverName;
        $cmd->serverPort = $tur->req->serverPort;
        $cmd->isSsl = $tur->isSecure;
        $tur->req->headers->copyTo($cmd->headers);
        //$cmd->headers.setHeader(Headers.HOST, docker.host + ":" + docker.port);
        //$cmd->headers.setHeader(Headers.CONNECTION, "keep-alive");
        $cmd->serverPort =  $wsip->docker->port;

        if(BayServer::$harbor->traceHeader()) {
            foreach($cmd->headers->names() as $name) {
                foreach($cmd->headers->values($name) as $value) {
                    BayLog::info("%s sendWarpHeader: %s=%s", WarpData::get($tur), $name, $value);
                }
            }
        }
        $wsip->post($cmd);
    }

    private function sendData(Tour $tur, string $data, int $ofs, int $len, ?callable $lis)
    {
        BayLog::debug("%s construct contents", $tur);

        $cmd = new CmdData($data, $ofs, $len);
        $cmd->toServer = true;
        $this->ship()->post($cmd, $lis);
    }

    private function ship(): WarpShip
    {
        return $this->protocolHandler->ship;
    }
}