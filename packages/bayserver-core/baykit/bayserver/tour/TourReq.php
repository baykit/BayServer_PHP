<?php
namespace baykit\bayserver\tour;

use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Sink;
use baykit\bayserver\Symbol;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\Reusable;

class TourReq implements Reusable {

    private Tour $tour;

    /**
     * Request Header info
     */
    public int $key;  // request id in FCGI or stream id in HTTP/2

    public ?string $uri;
    public ?string $protocol;
    public ?string $method;

    public Headers $headers;

    public ?string $rewrittenURI = null; // set if URI is rewritten
    public ?string $queryString = null;
    public ?string $pathInfo = null;
    public ?string $scriptName = null;
    public ?string $reqHost;  // from Host header
    public string $reqPort;     // from Host header

    public ?string $remoteUser = null;
    public ?string $remotePass = null;

    public ?string $remoteAddress = null;
    public int $remotePort;
    public $remoteHostFunc;   // callable. (Remote host is resolved on demand since performance reason)
    public ?string $serverAddress = null;
    public int $serverPort;
    public ?string $serverName = null;
    public ?string $charset = null;

    /**
     * Request content info
     */
    public int $bytesPosted = 0;
    public int $bytesConsumed = 0;
    public int $bytesLimit = 0;
    public ?ReqContentHandler $contentHandler = null;
    public bool $available = false;
    public bool $ended = false;

    public function __construct(Tour $tur)
    {
        $this->tour = $tur;
        $this->headers = new Headers();
    }


    //////////////////////////////////////////////////////////////////
    /// Implements Reusable
    //////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        $this->headers->clear();

        $this->uri = null;
        $this->method = null;
        $this->protocol = null;
        $this->bytesPosted = 0;
        $this->bytesConsumed = 0;
        $this->bytesLimit = 0;

        $this->key = 0;

        $this->rewrittenURI = null;
        $this->queryString = null;
        $this->pathInfo = null;
        $this->scriptName = null;
        $this->reqHost = null;
        $this->reqPort = 0;

        $this->remoteUser = null;
        $this->remotePass = null;

        $this->remoteAddress = null;
        $this->remotePort = 0;
        $this->remoteHostFunc = null;
        $this->serverAddress = null;
        $this->serverPort = 0;
        $this->serverName = null;
        $this->charset = null;

        $this->contentHandler = null;
        $this->available = false;
        $this->ended = false;
    }

    //////////////////////////////////////////////////////////////////
    /// Other methods
    //////////////////////////////////////////////////////////////////

    public function init(int $key) : void
    {
        $this->key = $key;
    }

    // Remote host are evaluated later because it needs host name lookup
    public function remoteHost() : ?string
    {
        if ($this->remoteHostFunc == null)
            return null;
        else
            return ($this->remoteHostFunc)();
    }

    public function setContentHandler(ReqContentHandler $hnd) : void
    {
        BayLog::debug("%s set content handler", $this->tour);

        if ($this->contentHandler !== null)
            throw new Sink("content handler already set");

        $this->contentHandler = $hnd;
    }

    public function setLimit(int $limit) : void
    {
        if ($limit < 0) {
            throw new Sink("Invalid limit");
        }
        $this->bytesLimit = $limit;
        $this->bytesConsumed = 0;
        $this->bytesPosted = 0;
        $this->available = true;
    }

    public function postReqContent(int $checkId, string $data, int $start, int $len, ?callable $callback) : bool
    {
        $this->tour->checkTourId($checkId);

        $dataPassed = false;
        if(!$this->tour->isReading()) {
            BayLog::debug("%s tour is not reading.", $this->tour);
        }
        else if ($this->tour->req->contentHandler == null) {
            BayLog::warn("%s content read, but no content handler", $this->tour);
        }
        else if ($this->bytesPosted + $len > $this->bytesLimit) {
            throw new ProtocolException(
                BayMessage::get(
                    Symbol::HTTP_READ_DATA_EXCEEDED,
                    $this->bytesPosted + $len,
                    $this->bytesLimit));
        }
        else if($this->tour->error != null) {
            // If has error, only read content. (Do not call content handler)
            BayLog::debug("%s tour has error.", $this->tour);
        }
        else {
            $this->contentHandler->onReadReqContent($this->tour, $data, $start, $len, $callback);
            $dataPassed = true;
        }

        $this->bytesPosted += $len;
        BayLog::debug("%s read content: len=%d posted=%d limit=%d consumed=%d available=%b",
                $this->tour, $len, $this->bytesPosted, $this->bytesLimit, $this->bytesConsumed, $this->available);

        if(!$dataPassed)
            return true;

        $oldAvailable = $this->available;
        if(!$this->bufferAvailable())
            $this->available = false;
        if($oldAvailable && !$this->available) {
            BayLog::debug("%s request unavailable (_ _).zZZ: posted=%d consumed=%d", $this,  $this->bytesPosted, $this->bytesConsumed);
        }

        return $this->available;
    }


    public function endReqContent(int $checkId) : void
    {
        BayLog::debug("%s endReqContent", $this->tour);
        $this->tour->checkTourId($checkId);
        if ($this->ended)
            throw new Sink("%s Request content is already ended", $this->tour);

        $this->tour->changeState(Tour::TOUR_ID_NOCHECK, Tour::STATE_RUNNING);
        if ($this->bytesLimit >= 0 && $this->bytesPosted != $this->bytesLimit) {
            throw new ProtocolException("Read data exceed content-length: " . $this->bytesPosted . "/" . $this->bytesLimit);
        }

        if ($this->contentHandler !== null)
            $this->contentHandler->onEndReqContent($this->tour);
        $this->ended = true;
    }

    public function consumed(int $checkId, int $length, ?callable $callback) : void
    {
        $this->tour->checkTourId($checkId);

        $this->bytesConsumed += $length;
        BayLog::debug("%s reqConsumed: len=%d posted=%d limit=%d consumed=%d available=%b",
            $this->tour, $length, $this->bytesPosted, $this->bytesLimit, $this->bytesConsumed, $this->available);

        $resume = false;

        $oldAvailable = $this->available;
        if($this->bufferAvailable())
            $this->available = true;
        if(!$oldAvailable && $this->available) {
            BayLog::debug("%s request available (^o^): posted=%d consumed=%d", $this,  $this->bytesPosted, $this->bytesConsumed);
            $resume = true;
        }

        $callback($length, $resume);
    }

    public function abort() : bool
    {
        BayLog::debug("%s abort state=%d", $this->tour, $this->tour->state);
        if ($this->tour->isPreparing()) {
            return true;
        }
        elseif ($this->tour->isReading()) {
            $aborted = true;
            if ($this->contentHandler != null)
                $aborted = $this->contentHandler->onAbortReq($this->tour);

            return $aborted;
        }
        else {
            BayLog::debug("%s tour is not preparing or not running: state=%d", $this->tour, $this->tour->state);
            return false;
        }
    }


    private function bufferAvailable() : bool
    {
        return $this->bytesPosted - $this->bytesConsumed < BayServer::$harbor->tourBufferSize();
    }
}

