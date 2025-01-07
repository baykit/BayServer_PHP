<?php

namespace baykit\bayserver\docker\fcgi;


use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\common\WarpData;
use baykit\bayserver\common\WarpHandler;
use baykit\bayserver\common\WarpShip;
use baykit\bayserver\docker\fcgi\command\CmdBeginRequest;
use baykit\bayserver\docker\fcgi\command\CmdEndRequest;
use baykit\bayserver\docker\fcgi\command\CmdParams;
use baykit\bayserver\docker\fcgi\command\CmdStdErr;
use baykit\bayserver\docker\fcgi\command\CmdStdIn;
use baykit\bayserver\docker\fcgi\command\CmdStdOut;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Sink;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\CGIUtil;
use baykit\bayserver\util\ClassUtil;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\StringUtil;

class FcgWarpHandler implements WarpHandler, FcgHandler
{
    const STATE_READ_HEADER = 1;
    const STATE_READ_CONTENT = 2;

    private FcgProtocolHandler $protocolHandler;
    private int $state;
    private int $curWarpId = 0;
    private string $lineBuf = "";
    private int $pos;
    private int $last;
    private ?string $data = null;

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
        $this->resetState();
        $this->lineBuf = "";
        $this->pos = 0;
        $this->last = 0;
        $this->data = null;
        $this->curWarpId++;
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
        $this->sendBeginReq($tur);
        $this->sendParams($tur);
    }

    public function sendReqContents(Tour $tur, string $buf, int $start, int $len, ?callable $callback): void
    {
        $this->sendStdIn($tur, $buf, $start, $len, $callback);
    }

    public function sendEndReq(Tour $tur, bool $keepAlive, ?callable $callback): void
    {
        $this->sendStdIn($tur, null, 0, 0, $callback);
    }

    public function verifyProtocol(string $protocol): void
    {
    }

    function onProtocolError(ProtocolException $e): bool
    {
        throw new Sink();
    }

    ///////////////////////////////////////////
    // Implements FcgCommandHandler
    ///////////////////////////////////////////

    public function handleBeginRequest(CmdBeginRequest $cmd): int
    {
        throw new ProtocolException("Invalid FCGI command: %d", $cmd->type);
    }

    public function handleEndRequest(CmdEndRequest $cmd): int
    {
        $tur = $this->ship()->getTour($cmd->reqId);
        $this->endReqContent($tur);
        return NextSocketAction::CONTINUE;
    }

    public function handleParams(CmdParams $cmd): int
    {
        throw new ProtocolException("Invalid FCGI command: " . $cmd->type);
    }

    public function handleStdErr(CmdStdErr $cmd): int
    {
        $msg = substr($cmd->data, $cmd->start, $cmd->length);
        BayLog::error("%s server error: s", $this, $msg);
        return NextSocketAction::CONTINUE;
    }

    public function handleStdIn(CmdStdIn $cmd): int
    {
        throw new ProtocolException("Invalid FCGI command: %d", $cmd->type);
    }

    public function handleStdOut(CmdStdOut $cmd): int
    {
        $tur = $this->ship()->getTour($cmd->reqId);
        if($tur == null)
            throw new Sink("Tour not found");

        if ($cmd->length == 0) {
            // stdout end
            $this->resetState();
            return NextSocketAction::CONTINUE;
        }

        $this->data = $cmd->data;
        $this->pos = $cmd->start;
        $this->last = $cmd->start + $cmd->length;

        if ($this->state == self::STATE_READ_HEADER)
            $this->readHeader($tur);

        if ($this->pos < $this->last) {
            if ($this->state == self::STATE_READ_CONTENT) {
                $available = $tur->res->sendResContent(Tour::TOUR_ID_NOCHECK, $this->data, $this->pos, $this->last - $this->pos);
                if(!$available)
                    return NextSocketAction::SUSPEND;
            }
        }

        return NextSocketAction::CONTINUE;
    }

    ///////////////////////////////////////////
    // Private methods
    ///////////////////////////////////////////

    private function readHeader(Tour $tur) : void
    {
        $wdat = WarpData::get($tur);

        $headerFinished = $this->parseHeader($wdat->resHeaders);
        if ($headerFinished) {

            $wdat->resHeaders->copyTo($tur->res->headers);

            // Check HTTP Status from headers
            $status = $wdat->resHeaders->get(Headers::STATUS);
            if (!StringUtil::isEmpty($status)) {
                $stlist = explode(" ", $status);
                $tur->res->headers->status = intval($stlist[0]);
                $tur->res->headers->remove(Headers::STATUS);
            }

            $sip = $this->ship();
            BayLog::debug("%s fcgi: read header status=%d contlen=",
                $sip, $status, $wdat->resHeaders->contentLength());

            $sid = $sip->id();
            $tur->res->setConsumeListener(function ($len, $resume) use ($sip, $sid) {
                if($resume) {
                    $sip->resumeRead($sid);
                }
            });

            $tur->res->sendResHeaders(Tour::TOUR_ID_NOCHECK);
            $this->changeState(self::STATE_READ_CONTENT);
        }
    }

    private function parseHeader(Headers $headers) : bool
    {
        while (true) {
            if ($this->pos == $this->last) {
                // no byte data
                break;
            }

            $c = $this->data[$this->pos++];

            if ($c == "\r")
                continue;
            else if ($c == "\n") {
                $line = strval($this->lineBuf);
                if (strlen($line) == 0)
                    return true;

                $colonPos = strpos($line, ':');
                if ($colonPos === false)
                    throw new ProtocolException("fcgi: Header line of server is invalid: " . $line);
                else {
                    $name = trim(substr($line, 0, $colonPos));
                    $value = trim(substr($line, $colonPos+1));

                    if (StringUtil::isEmpty($name) || StringUtil::isEmpty($value))
                        throw new ProtocolException("fcgi: Header line of server is invalid: " . $line);
                    $headers->add($name, $value);
                    if (BayServer::$harbor->traceHeader())
                        BayLog::info("%s fcgi_warp: resHeader: %s=%s", $this->ship, $name, $value);
                }
                $this->lineBuf = "";
            } else {
                $this->lineBuf .= $c;
            }
        }
        return false;
    }

    private function endReqContent(Tour $tur) : void
    {
        $this->ship()->endWarpTour($tur, true);
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

    private function sendStdIn(Tour $tur, ?string $data, int $ofs, int $len, ?callable $callback) : void
    {
        $cmd = new CmdStdIn(WarpData::get($tur)->warpId, $data, $ofs, $len);
        $this->ship()->post($cmd, $callback);
    }

    private function sendBeginReq(Tour $tur) : void
    {
        $cmd = new CmdBeginRequest(WarpData::get($tur)->warpId);
        $cmd->role = CmdBeginRequest::FCGI_RESPONDER;
        $cmd->keepConn = true;
        $this->ship()->post($cmd);
    }

    private function sendParams(Tour $tur) : void
    {
        $scriptBase =  $this->ship()->docker->scriptBase;
        if($scriptBase == null)
            $scriptBase = $tur->town->location;

        if(StringUtil::isEmpty($scriptBase)) {
            throw new IOException($tur->town . " scriptBase of fcgi docker or location of town is not specified.");
        }

        $docRoot = $this->ship()->docker->docRoot;
        if($docRoot == null)
            $docRoot = $tur->town->location;

        if(StringUtil::isEmpty($docRoot)) {
            throw new IOException($tur->town . " docRoot of fcgi docker or location of town is not specified.");
        }

        $warpId = WarpData::get($tur)->warpId;
        $cmd = new CmdParams($warpId);

        $scriptFname = "";
        CGIUtil::getEnv($tur->town->name, $docRoot, $scriptBase, $tur, function ($name, $value) use ($cmd, &$scriptFname) {
            if($name == CGIUtil::SCRIPT_FILENAME)
                $scriptFname = $value;
            else
                $cmd->addParam($name, $value);
        });

        $scriptFname = "proxy:fcgi://" . $this->ship()->docker->host . ":" .  $this->ship()->docker->port . $scriptFname;
        $cmd->addParam(CGIUtil::SCRIPT_FILENAME, $scriptFname);

        $cmd->addParam(FcgParams::CONTEXT_PREFIX, "");
        $cmd->addParam(FcgParams::UNIQUE_ID, strval(time()));


        if(BayServer::$harbor->traceHeader()) {
            foreach ($cmd->params as $kv) {
                BayLog::info("%s fcgi_warp: env: %s=%s", $this->ship, $kv[0], $kv[1]);
            }
        }

        $this->ship()->post($cmd);

        $cmdParamsEnd = new CmdParams($warpId);
        $this->ship()->post($cmdParamsEnd);
    }

    private function ship(): WarpShip
    {
        return $this->protocolHandler->ship;
    }

}