<?php
namespace baykit\bayserver\tour;

use baykit\bayserver\agent\transporter\PlainTransporter;
use baykit\bayserver\agent\transporter\SpinReadTransporter;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\docker\Harbor;
use baykit\bayserver\HttpException;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Sink;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\Mimes;
use baykit\bayserver\util\Reusable;
use baykit\bayserver\util\StringUtil;

class TourRes implements Reusable {

    private $tour;

    /**
     * Response header info
     */
    public $headers;

    public $charset;
    public $headerSent;

    /**
     * Response content info
     */
    public $available;
    public $bytesPosted;
    public $bytesConsumed;
    public $bytesLimit;
    public $resConsumeListener;
    public $tourReturned;

    private $canCompress;
    private $compressor;
    private $yacht;

    public function __construct(Tour $tur)
    {
        $this->tour = $tur;
        $this->headers = new Headers();
        $this->tourReturned = false;
    }

    public function __toString()
    {
        return $this->tour->__toString();
    }

    public function init() : void
    {
        $this->yacht = new SendFileYacht();
    }

    //////////////////////////////////////////////////////////////////
    /// Implements Reusable
    //////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        $this->headers->clear();
        $this->bytesPosted = 0;
        $this->bytesConsumed = 0;
        $this->bytesLimit = 0;

        $this->charset = null;
        $this->headerSent = false;
        $this->yacht->reset();
        $this->available = false;
        $this->resConsumeListener = null;
        $this->canCompress = false;
        $this->compressor = null;
        $this->tourReturned = false;
    }

    public function charset() : ?string
    {
        if (StringUtil::isEmpty($this->charset))
            return null;
        else
            return $this->charset;
    }

    public function setCharset(string $charset) : void
    {
        $this->charset = StringUtil::parseCharset($charset);
    }


    public function sendHeaders(int $checkId) : void
    {
        $this->tour->checkTourId($checkId);

        if ($this->tour->isZombie())
            return;

        if ($this->headerSent)
            return;

        $this->bytesLimit = $this->headers->contentLength();

        // Compress check
        if (BayServer::$harbor->gzipComp&&
                $this->headers->contains(Headers::CONTENT_TYPE) &&
                StringUtil::startsWith(strtolower($this->headers->contentType()), "text/") &&
                !$this->headers->contains(Headers::CONTENT_ENCODING)) {
            $enc = $this->tour->req->headers->get(Headers::ACCEPT_ENCODING);
            if ($enc !== null) {
                $tokens = explode(",", $enc);
                foreach($tokens as $t) {
                    if (StringUtil::eqIgnorecase(trim($t), "gzip")) {
                        $this->canCompress = true;
                        $this->headers->set(Headers::CONTENT_ENCODING, "gzip");
                        $this->headers->remove(Headers::CONTENT_LENGTH);
                        break;
                    }
                }
            }
        }

        try {
            $this->tour->ship->sendHeaders($this->tour->shipId, $this->tour);
        }
        catch(IOException $e) {
            BayLog::debug_e($e, "%s abort: %s", $this->tour, $e->getMessage());
            $this->tour->changeState(Tour::TOUR_ID_NOCHECK, Tour::STATE_ABORTED);
            throw $e;
        }
        finally {
            $this->headerSent = true;
        }
    }

    public function sendRedirect($chkId, $status, $location) : void
    {
        $this->tour->checkTourId($chkId);

        if($this->headerSent) {
            BayLog.error("Try to redirect after response header is sent (Ignore)");
        }
        else {
            $this->setConsumeListener(ContentConsumeListener::$devNull);
            try {
                $this->tour->ship->sendRedirect($this->tour->shipId, $this->tour, $status, $location);
            }
            catch(IOException $e) {
                $this->tour->changeState(Tour::TOUR_ID_NOCHECK, Tour::STATE_ABORTED);
                throw $e;
            }
            finally {
                $this->headerSent = true;
                $this->endContent($chkId);
            }
        }
    }

    public function setConsumeListener(callable $listener) : void
    {
        $this->resConsumeListener =$listener;
        $this->bytesConsumed = 0;
        $this->bytesPosted = 0;
        $this->available = true;
    }


    public function sendContent(int $checkId, string $buf, int $ofs, int $len) : bool
    {
        $this->tour->checkTourId($checkId);
        BayLog::debug("%s sendContent len=%d", $this->tour, $len);

        // Callback
        $consumed_cb = function () use ($len, $checkId) {
            $this->consumed($checkId, $len);
        };

        if ($this->tour->isZombie()) {
            BayLog::debug("%s zombie return", $this);
            $consumed_cb();
            return true;
        }

        if (!$this->headerSent)
            throw new Sink("BUG!: Header not sent");

        if ($this->resConsumeListener === null)
            throw new Sink("Response consume listener is null");

        $this->bytesPosted += $len;
        BayLog::debug("%s post res content len=%d posted=%d limit=%d consumed=%d",
            $this->tour, $len, $this->bytesPosted, $this->bytesLimit, $this->bytesConsumed);

        if($this->tour->isZombie() || $this->tour->isAborted()) {
            // Don't send peer any data. Do nothing
            BayLog::debug("%s Aborted or zombie tour. do nothing: %s state=%s", $this, $this->tour, $tur->state);
            $consumed_cb();
        }
        else {
            if ($this->canCompress) {
                $this->getCompressor()->compress($buf, $ofs, $len, $consumed_cb);
            }
            else {
                try {
                    $this->tour->ship->sendResContent($this->tour->shipId, $this->tour, $buf, $ofs, $len, $consumed_cb);
                }
                catch(IOException $e) {
                    $consumed_cb();
                    $this->tour->changeState(Tour::TOUR_ID_NOCHECK, Tour::STATE_ABORTED);
                    throw $e;
                }
            }
        }

        if ($this->bytesLimit > 0 && $this->bytesPosted > $this->bytesLimit) {
            throw new ProtocolException("Post data exceed content-length: {$this->bytesPosted}/{$this->bytesLimit}");
        }

        $oldAvailable = $this->available;
        if(!$this->bufferAvailable())
            $this->available = false;
        if($oldAvailable && !$this->available)
            BayLog::debug("%s response unavailable (_ _): posted=%d consumed=%d", $this, $this->bytesPosted, $this->bytesConsumed);

        return $this->available;
    }

    public function endContent(int $checkId) : void
    {
        $this->tour->checkTourId($checkId);

        BayLog::debug("%s end ResContent", $this);

        if ($this->tour->isEnded()) {
            BayLog::debug("%s Tour is already ended (Ignore).", $this);
            return;
        }

        if (!$this->tour->isZombie() && $this->tour->city !== null)
            $this->tour->city->log($this->tour);

        // send end message
        if ($this->canCompress) {
            $this->getCompressor()->finish();
        }

        // Callback
        $callback = function () use ($checkId) {
            $this->tour->checkTourId($checkId);
            $this->tour->ship->returnTour($this->tour);
            $this->tourReturned = true;
        };

        try {
            if($this->tour->isZombie() || $this->tour->isAborted()) {
                // Don't send peer any data. Only return tour
                BayLog::debug("%s Aborted or zombie tour. do nothing: %s state=%s", $this, $this->tour, $this->tour->state);
                $callback();
            }
            else {
                try {
                    $this->tour->ship->sendEndTour($this->tour->shipId, $this->tour, $callback);
                }
                catch(IOException $e) {
                    BayLog::debug("%s Error on sending end tour", $this);
                    $callback();
                    throw $e;
                }
            }
        }
        finally {
            BayLog::debug("%s Tour is returned: %s", $this, $this->tourReturned);
            if (!$this->tourReturned) {
                $this->tour->changeState(Tour::TOUR_ID_NOCHECK, Tour::STATE_ENDED);
            }
        }
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Sending error methods
    ////////////////////////////////////////////////////////////////////////////////

    public function sendHttpException(int $checkId, HttpException $e) : void
    {
        if ($e->status == HttpStatus::MOVED_TEMPORARILY || $e->status == HttpStatus::MOVED_PERMANENTLY)
            $this->sendRedirect($checkId, $e->status, $e->location);
        else
            $this->sendError($checkId, $e->status, $e->getMessage(), $e);
    }

    public function sendError(int $checkId, int $status, string $message, \Throwable $e=null) : void
    {
        $this->tour->checkTourId($checkId);

        if ($this->tour->isZombie())
            return;

        if ($this->headerSent) {
            BayLog::warn("Try to send error after response header is sent (Ignore)");
            BayLog::warn("%s: status=%d, message=%s", $this, $status, $message);
            if ($e !== null)
                BayLog::error_e($e);
        } else {
            $this->setConsumeListener(function ($len, $resume) {});

            if($this->tour->isZombie() || $this->tour->isAborted()) {
                # Don't send peer any data
                BayLog::debug("%s Aborted or zombie tour. do nothing: %s state=%s", $this, $this->tour, $this->tour->state);
            }
            else {
                try {
                    $this->tour->ship->sendError($this->tour->shipId, $this->tour, $status, $message, $e);
                }
                catch(IOException $e) {
                    BayLog::error_e($e, "%s Error in sending error", $this);
                    $this->tour->changeState(Tour::TOUR_ID_NOCHECK, Tour::STATE_ABORTED);
                }
            }
            $this->headerSent = true;
        }
        $this->endContent($checkId);
    }


    ////////////////////////////////////////////////////////////////////////////////
    // Sending file methods
    ////////////////////////////////////////////////////////////////////////////////

    public function sendFile(int $checkId, string $fname, ?string $charset, bool $async) : void
    {
        $this->tour->checkTourId($checkId);

        if ($this->tour->isZombie())
            return;

        if (is_dir($fname)) {
            throw new HttpException(HttpStatus::FORBIDDEN, $fname);
        }
        elseif (!file_exists($fname)) {
            throw new HttpException(HttpStatus::NOT_FOUND, $fname);
        }

        $mimeType = null;

        $rname = basename($fname);
        $pos = strrpos($rname, '.');
        if ($pos >= 0) {
            $ext = strtolower(substr($rname, $pos + 1));
            $mimeType = Mimes::type($ext);
        }

        if ($mimeType === null)
            $mimeType = "application/octet-stream";

        if (StringUtil::startsWith($mimeType, "text/") && $this->charset() !== null)
            $mimeType = $mimeType . "; charset=" . $this->charset;

        //resHeaders.setStatus(HttpStatus.OK);
        $this->headers->setContentType($mimeType);
        $this->headers->setContentLength(filesize($fname));
        try {
            $this->sendHeaders(Tour::TOUR_ID_NOCHECK);

            if ($async) {
                $bufsize = $this->tour->ship->protocolHandler->maxResPacketDataSize();
                $infile = fopen($fname, "rb");

                switch(BayServer::$harbor->fileSendMethod) {
                    case Harbor::FILE_SEND_METHOD_SELECT: {
                        stream_set_blocking($infile, false);

                        $tp = new PlainTransporter(false, $bufsize);
                        $this->yacht->init($this->tour, $fname, filesize($fname), $tp);
                        $tp->init($this->tour->ship->agent->nonBlockingHandler, $infile, $this->yacht);
                        $tp->openValve();
                        break;
                    }
                    case Harbor::FILE_SEND_METHOD_SPIN: {
                        $timeout = 10;
                        stream_set_blocking($infile, false);

                        $tp = new SpinReadTransporter($bufsize);
                        $this->yacht->init($this->tour, $fname,filesize($fname), $tp);
                        $tp->init($this->tour->ship->agent->spinHandler, $this->yacht, $infile, filesize($fname), $timeout, nil);
                        $tp->openValve();
                        break;
                    }

                    case Harbor::FILE_SEND_METHOD_TAXI:
                        throw new Sink();
                }

            }
            else {
                throw new Sink();
            }
        }
        catch (IOException $e) {
            BayLog::error_e(e);
            throw new HttpException(HttpStatus::INTERNAL_SERVER_ERROR, $fname);
        }
    }


    ////////////////////////////////////////////////////////////////////////////////
    // Other methods
    ////////////////////////////////////////////////////////////////////////////////

    private function consumed(int $checkId, int $length) : void
    {
        $this->tour->checkTourId($checkId);
        if ($this->resConsumeListener === null)
            throw new Sink("Response consume listener is null");

        $this->bytesConsumed += $length;

        BayLog::debug("%s resConsumed: len=%d posted=%d consumed=%d limit=%d",
                $this->tour, $length, $this->bytesPosted, $this->bytesConsumed, $this->bytesLimit);

        $resume = false;
        $oldAvailable = $this->available;
        if($this->bufferAvailable())
            $this->available = true;
        if(!$oldAvailable && $this->available) {
            BayLog::debug("%s response available (^o^): posted=%d consumed=%d", $this,  $this->bytesPosted, $this->bytesConsumed);
            $resume = true;
        }

        if($this->tour->isRunning()) {
            ($this->resConsumeListener)($length, $resume);
        }
    }

    private function bufferAvailable() : bool
    {
        return $this->bytesPosted - $this->bytesConsumed < BayServer::$harbor->tourBufferSize;
    }
}

