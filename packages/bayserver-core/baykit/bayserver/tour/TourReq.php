<?php
namespace baykit\bayserver\tour;

use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\docker\base\InboundShip;
use baykit\bayserver\HttpException;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Sink;
use baykit\bayserver\Symbol;
use baykit\bayserver\util\Counter;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\Reusable;
use Couchbase\BaseException;

class TourReq implements Reusable {

    private $tour;

    /**
     * Request Header info
     */
    public $key;  // request id in FCGI or stream id in HTTP/2

    public $uri;
    public $protocol;
    public $method;

    public $headers;

    public $rewrittenURI; // set if URI is rewritten
    public $queryString;
    public $pathInfo;
    public $scriptName;
    public $reqHost;  // from Host header
    public $reqPort;     // from Host header

    public $remoteUser;
    public $remotePass;

    public $remoteAddress;
    public $remotePort;
    public $remoteHostFunc;   // callable. (Remote host is resolved on demand since performance reason)
    public $serverAddress;
    public $serverPort;
    public $serverName;
    public $charset;

    /**
     * Request content info
     */
    public $bytesPosted;
    public $bytesConsumed;
    public $bytesLimit;
    public $contentHandler;
    public $consumeListener;
    public $available;
    public $ended;

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
        $this->bytesPosted = null;
        $this->bytesConsumed = null;
        $this->bytesLimit = null;

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
        $this->consumeListener = null;
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

    public function setConsumeListener(int $limit, callable $listener) : void
    {
        if ($limit < 0) {
            throw new Sink("Invalid limit");
        }
        $this->consumeListener = $listener;
        $this->bytesLimit = $limit;
        $this->bytesConsumed = 0;
        $this->bytesPosted = 0;
        $this->available = true;
    }

    public function postContent(int $checkId, string $data, int $start, int $len) : bool
    {
        $this->tour->checkTourId($checkId);

        $dataPassed = false;
        if(!$this->tour->isRunning()) {
            BayLog::debug("%s tour is not running.", $this->tour);
        }
        else if ($this->tour->req->contentHandler == null) {
            BayLog::warn("%s content read, but no content handler", $this->tour);
        }
        else if($this->consumeListener == null) {
            throw new Sink("Request consume listener is null");
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
            $this->contentHandler->onReadContent($this->tour, $data, $start, $len);
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


    public function endContent(int $checkId) : void
    {
        BayLog::debug("%s endReqContent", $this->tour);
        $this->tour->checkTourId($checkId);
        if ($this->ended)
            throw new Sink("%s Request content is already ended", $this->tour);

        if ($this->bytesLimit >= 0 && $this->bytesPosted != $this->bytesLimit) {
            throw new ProtocolException("Read data exceed content-length: " . $this->bytesPosted . "/" . $this->bytesLimit);
        }

        if ($this->contentHandler !== null)
            $this->contentHandler->onEndContent($this->tour);
        $this->ended = true;
    }

    public function consumed(int $checkId, int $length) : void
    {
        $this->tour->checkTourId($checkId);
        if ($this->consumeListener === null)
            throw new Sink("Request consume listener is null");

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

        ($this->consumeListener)($length, $resume);
    }

    public function abort() : bool
    {
        BayLog::debug("%s abort", $this->tour);
        if ($this->tour->isPreparing()) {
            $this->tour->changeState(Tour::TOUR_ID_NOCHECK, Tour::STATE_ABORTED);
            return true;
        }
        elseif ($this->tour->isRunning()) {
            $aborted = true;
            if ($this->contentHandler != null)
                $aborted = $this->contentHandler->onAbort($this->tour);

            if($aborted)
                $this->tour->changeState(Tour::TOUR_ID_NOCHECK, Tour::STATE_ABORTED);

            return $aborted;
        }
        else {
            BayLog::debug("%s tour is not preparing or not running", $this->tour);
            return false;
        }
    }


    private function bufferAvailable() : bool
    {
        return $this->bytesPosted - $this->bytesConsumed < BayServer::$harbor->tourBufferSize;
    }
}

