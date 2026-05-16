<?php
namespace baykit\bayserver\docker\h3;

use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\common\InboundHandler;
use baykit\bayserver\docker\h3\command\CmdData;
use baykit\bayserver\docker\h3\command\CmdFinished;
use baykit\bayserver\docker\h3\command\CmdHeader;
use baykit\bayserver\HttpException;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Symbol;
use baykit\bayserver\tour\ReqContentHandlerUtil;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\HttpUtil;
use baykit\bayserver\util\IOException;
use Croute\CrouteError;

/**
 * Bridges H3 events (HEADERS / DATA / FINISHED) from
 * QicProtocolHandler to BayServer Tours.
 *
 * Send path  (server -> client): sendResHeaders / sendResContent /
 * sendEndTour translate to {@link \Croute\H3\Connection::sendResponse}
 * / sendBody. When libquiche reports the stream is blocked the
 * send is buffered as a {@link PartialResponse} on the protocol
 * handler and replayed from QicProtocolHandler::onStreamWritable.
 *
 * Receive path (client -> server): handleHeaders maps pseudo-headers
 * to Tour fields and starts the tour; handleData pulls body bytes
 * with {@link \Croute\H3\Connection::recvBody} and feeds them to
 * the request content handler; handleFinished is informational.
 *

 */
class QicInboundHandler implements QicHandler, InboundHandler
{
    public ?QicProtocolHandler $protocolHandler = null;

    public function init(QicProtocolHandler $proto) : void
    {
        $this->protocolHandler = $proto;
    }

    public function reset() : void {}

    public function __toString() : string
    {
        return "QicInboundHandler";
    }

    //////////////////////////////////////////////////////
    // Implements InboundHandler -- send side
    //////////////////////////////////////////////////////

    public function sendResHeaders(Tour $tur) : void
    {
        $stmId = $tur->req->key;
        BayLog::debug("%s stm#%d sendResHeader", $tur, $stmId);

        $headers = [[":status", (string)$tur->res->headers->status]];
        foreach ($tur->res->headers->names() as $name) {
            foreach ($tur->res->headers->values($name) as $value) {
                $headers[] = [$name, $value];
            }
        }

        if (BayServer::$harbor->traceHeader()) {
            foreach ($headers as [$n, $v]) {
                BayLog::info("%s stm#%d header %s: %s", $tur, $stmId, $n, $v);
            }
        }

        try {
            $this->protocolHandler->h3con->sendResponse($stmId, $headers, false);
            $this->protocolHandler->postPackets();
        } catch (CrouteError $e) {
            if ($e->errCode === QuicheErrorCode::H3_ERR_STREAM_BLOCKED) {
                BayLog::warn("%s stm#%d sending header is blocked", $tur, $stmId);
                $this->protocolHandler->addPartialResponse($stmId, new PartialResponse($headers));
            } else if ($e->errCode === QuicheErrorCode::H3_ERR_TRANSPORT_ERROR) {
                throw new IOException("h3: send header failed (transport)");
            } else {
                throw new IOException("h3: send header failed: {$e->getMessage()}({$e->errCode})");
            }
        }
    }

    public function sendResContent(Tour $tur, string $bytes, int $ofs, int $len, ?callable $callback) : bool
    {
        $stmId = $tur->req->key;
        BayLog::debug("%s stm#%d sendResContent len=%d", $tur, $stmId, $len);

        $buf = ($ofs > 0 || $len < strlen($bytes)) ? substr($bytes, $ofs, $len) : $bytes;
        $part = null;

        if (isset($this->protocolHandler->partialResponses[$stmId])) {
            BayLog::trace("%s stm#%d waiting, queueing len=%d", $tur, $stmId, $len);
            $part = new PartialResponse(null, $buf, 0, false, $callback);
        } else {
            try {
                $written = $this->protocolHandler->h3con->sendBody($stmId, $buf, false);
            } catch (CrouteError $e) {
                if ($e->errCode === QuicheErrorCode::H3_ERR_FRAME_UNEXPECTED) {
                    $part = new PartialResponse(null, $buf, 0, false, $callback);
                    $written = -1;
                } else if ($e->errCode === QuicheErrorCode::H3_ERR_TRANSPORT_ERROR) {
                    throw new IOException("h3: send body failed (transport)");
                } else {
                    throw new IOException("h3: send body failed: {$e->getMessage()}({$e->errCode})");
                }
            }

            if ($part === null) {
                BayLog::debug("stm#%d sent %d/%d bytes body", $stmId, $written, $len);
                if ($written === 0) {
                    $part = new PartialResponse(null, $buf, 0, false, $callback);
                } else if ($written < $len) {
                    $part = new PartialResponse(null, substr($buf, $written), 0, false, $callback);
                }
            }
        }

        if ($part !== null) {
            $this->protocolHandler->addPartialResponse($stmId, $part);
        } else if ($callback !== null) {
            $callback(true);
        }
        $this->protocolHandler->postPackets();
        return true;
    }

    public function sendEndTour(Tour $tur, ?callable $callback) : void
    {
        $stmId = $tur->req->key;
        BayLog::debug("%s stm#%d sendEndTour", $tur, $stmId);
        $retry = false;

        if (isset($this->protocolHandler->partialResponses[$stmId])) {
            BayLog::debug("stm#%d queue pending end", $stmId);
            $retry = true;
        } else {
            try {
                $this->protocolHandler->h3con->sendBody($stmId, "", true);
                BayLog::debug("stm#%d send finish", $stmId);
            } catch (CrouteError $e) {
                if ($e->errCode === QuicheErrorCode::H3_ERR_FRAME_UNEXPECTED) {
                    BayLog::warn("stm#%d send end content frame unexpected", $stmId);
                    $retry = true;
                } else if ($e->errCode === QuicheErrorCode::H3_ERR_TRANSPORT_ERROR) {
                    throw new IOException("h3: send body failed (transport)");
                } else {
                    throw new IOException("h3: send body failed: {$e->getMessage()}({$e->errCode})");
                }
            }
        }

        if ($retry) {
            $this->protocolHandler->addPartialResponse($stmId, new PartialResponse(null, null, 0, true, $callback));
        } else if ($callback !== null) {
            $callback(true);
        }
        $this->protocolHandler->postPackets();
    }

    public function transferContent(Tour $tur, \baykit\bayserver\rudder\Rudder $fileRd, int $ofs, int $len, ?callable $callback) : void
    {
        throw new \baykit\bayserver\Sink("H3 has no sendfile equivalent");
    }

    public function sendReqProtocolError(ProtocolException $e) : bool
    {
        BayLog::debug_e($e);
        return false;
    }

    //////////////////////////////////////////////////////
    // Implements QicHandler -- receive side
    //////////////////////////////////////////////////////

    public function onProtocolError(ProtocolException $e) : bool
    {
        BayLog::debug_e($e);
        return false;
    }

    public function handleHeaders(CmdHeader $cmd) : void
    {
        $stmId = $cmd->stmId;
        BayLog::debug("%s stm#%d onHeaders", $this, $stmId);

        try {
            $tur = $this->getTour($stmId);
            if ($tur === null) {
                $this->tourIsUnavailable($stmId);
                return;
            }

            if (!$this->validatePseudoHeaders($cmd, $stmId)) {
                $this->closeWithH3Error(H3ErrorCode::MESSAGE_ERROR, "malformed pseudo-headers");
                return;
            }

            foreach ($cmd->reqHeaders as [$name, $value]) {
                if (BayServer::$harbor->traceHeader()) {
                    BayLog::info("%s stm#%d ReqHeader %s=%s", $tur, $stmId, $name, $value);
                }
                $lname = strtolower($name);
                switch ($lname) {
                    case ":path":      $tur->req->uri = $value; break;
                    case ":authority": $tur->req->headers->add(Headers::HOST, $value); break;
                    case ":scheme":    $tur->isSecure = strcasecmp($value, "https") === 0; break;
                    case ":method":    $tur->req->method = $value; break;
                    default:
                        if ($lname === "" || $lname[0] !== ':') {
                            $tur->req->headers->add($name, $value);
                        }
                        break;
                }
            }

            BayLog::debug("%s stm#%d onHeader: method=%s uri=%s", $tur, $stmId, $tur->req->method, $tur->req->uri);

            $reqContLen = $tur->req->headers->contentLength();
            if ($reqContLen > 0) {
                $tur->req->setLimit($reqContLen);
            }

            try {
                $this->startTour($tur);
                if ($tur->req->headers->contentLength() <= 0) {
                    $this->endReqContent($tur->id(), $tur);
                }
            } catch (HttpException $e) {
                BayLog::debug_e($e, "%s Http error occurred", $this);
                if ($reqContLen <= 0) {
                    $tur->res->sendHttpException(Tour::TOUR_ID_NOCHECK, $e);
                } else {
                    $tur->error = $e;
                    $tur->req->setContentHandler(ReqContentHandlerUtil::$devNull);
                }
            }
        } catch (\Throwable $e) {
            BayLog::error_e($e);
        }
    }

    public function handleData(CmdData $cmd) : void
    {
        $stmId = $cmd->stmId;
        BayLog::debug("%s stm#%d onData", $this, $stmId);

        try {
            $tur = $this->getTour($stmId);
            if ($tur === null) {
                $this->tourIsUnavailable($stmId);
                return;
            }

            $buf = str_repeat("\0", QicProtocolHandler::MAX_DATAGRAM_SIZE);
            try {
                $nRead = $this->protocolHandler->h3con->recvBody($stmId, $buf);
            } catch (CrouteError $e) {
                BayLog::error("%s stm#%d h3 recv body failed: %s(%d)", $this, $stmId, $e->getMessage(), $e->errCode);
                return;
            }

            if ($nRead !== QuicheErrorCode::H3_ERR_DONE && $nRead > 0) {
                $sid = $this->protocolHandler->ship->shipId;
                $tur->req->postReqContent(Tour::TOUR_ID_NOCHECK,
                    $buf, 0, $nRead,
                    function ($length, $resume) use ($tur, $sid) {
                        if ($resume) $tur->ship->resumeRead($sid);
                    });
            }

            if ($tur->req->bytesPosted >= $tur->req->headers->contentLength()) {
                if ($tur->error !== null) {
                    $tur->res->sendHttpException(Tour::TOUR_ID_NOCHECK, $tur->error);
                } else {
                    try {
                        $this->endReqContent($tur->id(), $tur);
                    } catch (HttpException $e) {
                        $tur->res->sendHttpException(Tour::TOUR_ID_NOCHECK, $e);
                    }
                }
            }
        } catch (\Throwable $e) {
            BayLog::error_e($e);
        }
    }

    public function handleFinished(CmdFinished $cmd) : void
    {
        BayLog::debug("%s stm#%d onFinished", $this, $cmd->stmId);
    }

    //////////////////////////////////////////////////////
    // Private
    //////////////////////////////////////////////////////

    private function getTour(int $stmId) : ?Tour
    {
        return $this->protocolHandler->ship->getTour($stmId);
    }

    private function tourIsUnavailable(int $stmId) : void
    {
        BayLog::error(BayMessage::get(Symbol::INT_NO_MORE_TOURS));
        $tur = $this->protocolHandler->ship->getTour($stmId, true);
        $tur->res->sendError(Tour::TOUR_ID_NOCHECK, HttpStatus::SERVICE_UNAVAILABLE, "No available tours");
    }

    private function endReqContent(int $checkId, Tour $tur) : void
    {
        BayLog::debug("%s endReqContent", $tur);
        $this->protocolHandler->con->streamShutdown($tur->req->key, \Croute\Binding::SHUTDOWN_READ, 0);
        $tur->req->endReqContent($checkId);
    }

    private function startTour(Tour $tur) : void
    {
        HttpUtil::parseHostPort($tur, 443);
        HttpUtil::parseAuthrization($tur);

        $tur->req->protocol = QicProtocolHandler::PROTOCOL;
        $tur->req->remotePort = $this->protocolHandler->peerAddr->port;
        $tur->req->remoteAddress = $this->protocolHandler->peerAddr->ip;
        $tur->req->remoteHostFunc = function () use ($tur) {
            return HttpUtil::resolveHost($tur->req->remoteAddress);
        };
        $tur->req->serverAddress = $this->protocolHandler->localAddr->ip;
        $tur->req->serverPort = $tur->req->reqPort;
        $tur->req->serverName = $tur->req->reqHost;
        $tur->isSecure = true;

        $tur->go();
    }

    /**
     * RFC 9114 §4.3 pseudo-header validation.
     */
    private function validatePseudoHeaders(CmdHeader $cmd, int $stmId) : bool
    {
        $seenPseudo = [];
        $sawRegular = false;
        $method = $scheme = $path = $authority = null;

        foreach ($cmd->reqHeaders as [$name, $value]) {
            if ($name === "") {
                BayLog::debug("%s stm#%d empty header name", $this, $stmId);
                return false;
            }
            if ($name[0] === ':') {
                if ($sawRegular) {
                    BayLog::debug("%s stm#%d pseudo-header %s after regular fields", $this, $stmId, $name);
                    return false;
                }
                if (isset($seenPseudo[$name])) {
                    BayLog::debug("%s stm#%d duplicated pseudo-header %s", $this, $stmId, $name);
                    return false;
                }
                $seenPseudo[$name] = true;
                switch ($name) {
                    case ":method":    $method = $value; break;
                    case ":scheme":    $scheme = $value; break;
                    case ":path":      $path = $value; break;
                    case ":authority": $authority = $value; break;
                    default:
                        BayLog::debug("%s stm#%d prohibited pseudo-header %s", $this, $stmId, $name);
                        return false;
                }
            } else {
                $sawRegular = true;
            }
        }

        if ($method === null) {
            BayLog::debug("%s stm#%d missing :method", $this, $stmId);
            return false;
        }
        if (strcasecmp($method, "CONNECT") !== 0) {
            if ($scheme === null) {
                BayLog::debug("%s stm#%d missing :scheme", $this, $stmId);
                return false;
            }
            if ($path === null || $path === "") {
                BayLog::debug("%s stm#%d missing :path", $this, $stmId);
                return false;
            }
            if ($authority === null) {
                $hasHost = false;
                foreach ($cmd->reqHeaders as [$n, $_]) {
                    if (strcasecmp($n, "host") === 0) { $hasHost = true; break; }
                }
                if (!$hasHost) {
                    BayLog::debug("%s stm#%d missing :authority and Host", $this, $stmId);
                    return false;
                }
            }
        }
        return true;
    }

    private function closeWithH3Error(int $errorCode, string $reason) : void
    {
        BayLog::debug("%s closing H3 connection: code=0x%x reason=%s", $this, $errorCode, $reason);
        try {
            $this->protocolHandler->con->closeConnection(true, $errorCode, $reason);
        } catch (CrouteError $e) {
            BayLog::debug("%s closeConnection failed: %s", $this, $e->getMessage());
        }
        try {
            $this->protocolHandler->postPackets();
        } catch (\Throwable $e) {
            BayLog::debug("%s postPackets after close failed: %s", $this, $e->getMessage());
        }
    }
}
