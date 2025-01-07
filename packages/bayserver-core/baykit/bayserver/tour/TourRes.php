<?php
namespace baykit\bayserver\tour;

use baykit\bayserver\agent\transporter\PlainTransporter;
use baykit\bayserver\agent\transporter\SpinReadTransporter;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\docker\Harbor;
use baykit\bayserver\docker\Trouble;
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

    private $canCompress;
    private $compressor;
    private $yacht;

    public function __construct(Tour $tur)
    {
        $this->tour = $tur;
        $this->headers = new Headers();
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


    public function sendResHeaders(int $checkId) : void
    {
        $this->tour->checkTourId($checkId);

        if($this->tour->isZombie() || $this->tour->isAborted())
            return;

        if ($this->headerSent)
            return;

        $this->bytesLimit = $this->headers->contentLength();

        // Compress check
        if (BayServer::$harbor->gzipComp() &&
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
            $handled = false;
            if(!$this->tour->errorHandling && $this->tour->res->headers->status >= 400) {
                $trb = BayServer::$harbor->trouble();
                if($trb !== null) {
                    $cmd = $trb->find($this->tour->res->headers->status);
                    if ($cmd !== null) {
                        $errTour = $this->tour->ship->getErrorTour();
                        $errTour->req->uri = $cmd->target;
                        $this->tour->req->headers->copyTo($errTour->req->headers);
                        $this->tour->res->headers->copyTo($errTour->res->headers);
                        $errTour->req->remotePort = $this->tour->req->remotePort;
                        $errTour->req->remoteAddress = $this->tour->req->remoteAddress;
                        $errTour->req->serverAddress = $this->tour->req->serverAddress;
                        $errTour->req->serverPort = $this->tour->req->serverPort;
                        $errTour->req->serverName = $this->tour->req->serverName;
                        $errTour->res->headerSent = $this->tour->res->headerSent;
                        $this->tour->changeState(Tour::TOUR_ID_NOCHECK, Tour::STATE_ZOMBIE);
                        switch ($cmd->method) {
                            case Trouble::GUIDE: {
                                $errTour->go();
                                break;
                            }

                            case Trouble::TEXT: {
                                $this->tour->ship->sendResHeaders($errTour);
                                $data = $cmd->target->getBytes();
                                $errTour->res->sendResContent(Tour::TOUR_ID_NOCHECK, $data, 0, strlen($data));
                                $errTour->res->endResContent(Tour::TOUR_ID_NOCHECK);
                                break;
                            }

                            case Trouble::REROUTE: {
                                $errTour->res->sendHttpException(Tour::TOUR_ID_NOCHECK, HttpException::movedTemp($cmd->target));
                                break;
                            }
                        }
                        $handled = true;
                    }
                }
            }

            if(!$handled) {
                $this->tour->ship->sendHeaders($this->tour->shipId, $this->tour);
            }
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
                $this->endResContent($chkId);
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


    public function sendResContent(int $checkId, string $buf, int $ofs, int $len) : bool
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
            BayLog::debug("%s Aborted or zombie tour. do nothing: %s state=%s", $this, $this->tour, $this->tour->state);
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

    public function endResContent(int $checkId) : void
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
        $tourReturned = false;
        $callback = function () use ($checkId, &$tourReturned) {
            $this->tour->checkTourId($checkId);
            $this->tour->ship->returnTour($this->tour);
            $tourReturned = true;
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
            // If tour is returned, we cannot change its state because
            // it will become uninitialized.
            BayLog::debug("%s Tour is returned: %s", $this, $tourReturned);
            if (!$tourReturned) {
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
            BayLog::debug("Try to send error after response header is sent (Ignore)");
            BayLog::debug("%s: status=%d, message=%s", $this, $status, $message);
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
        $this->endResContent($checkId);
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
        return $this->bytesPosted - $this->bytesConsumed < BayServer::$harbor->tourBufferSize();
    }
}

