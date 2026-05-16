<?php
namespace baykit\bayserver\tour;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\multiplexer\PlainTransporter as MultiplexerPlainTransporter;
use baykit\bayserver\agent\transporter\PlainTransporter;
use baykit\bayserver\agent\transporter\SpinReadTransporter;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\common\RudderState;
use baykit\bayserver\common\RudderStateStore;
use baykit\bayserver\docker\file\SendFileShip;
use baykit\bayserver\docker\Harbor;
use baykit\bayserver\docker\Trouble;
use baykit\bayserver\HttpException;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\rudder\StreamRudder;
use baykit\bayserver\Sink;
use baykit\bayserver\util\DirectoryException;
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
     * Response content info. dropped per-tour bytesConsumed
     * and `available` -- back-pressure now rides the multiplexer's
     * RudderState.bufferAvailable() through the consume listener chain.
     */
    public $bytesPosted;
    public $bytesLimit;
    public $resConsumeListener;

    private $canCompress;
    private $compressor;
    private $yacht;

    /**
     * Direct Boarding flag (mirrors upstream's TourRes.directBoarding, set from
     * harbor config in init()). PHP cannot actually do non-blocking
     * sendfile so SpiderMultiplexer.reqTransfer throws Sink at runtime
     * the framework hooks are wired up only for parity.
     */
    public bool $directBoarding = false;

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
        $this->directBoarding = BayServer::$harbor->directBoarding();
    }

    //////////////////////////////////////////////////////////////////
    /// Implements Reusable
    //////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        $this->headers->clear();
        $this->bytesPosted = 0;
        $this->bytesLimit = 0;

        $this->charset = null;
        $this->headerSent = false;
        $this->yacht->reset();
        $this->resConsumeListener = null;
        $this->canCompress = false;
        $this->compressor = null;
        $this->directBoarding = false;
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
        //$this->tour->checkTourId($checkId);

        if($this->tour->isZombie() || $this->tour->isAborted())
            return;

        if ($this->headerSent)
            return;

        // If this tour is feeding a barge (cache), capture the headers
        // before they go out so subsequent hits can serve from memory.
        if ($this->tour->cargo !== null) {
            $this->tour->cargo->saveHeaders($this->headers);
        }

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
        //$this->tour->checkTourId($chkId);

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

    /**
     * Open the given file and stream its contents to the client.
     *
     * The implementation opens an async file channel and pumps through
     * SendFileShip. PHP cannot do non-blocking sendfile(2), so the
     * Direct Boarding code path that would route through reqTransfer
     * is not available; reqTransfer throws Sink in SpiderMultiplexer,
     * but the directBoarding flag is still wired up so the framework
     * code in BuiltInCityDocker / MemBargeDocker can consult it.
     */
    public function sendFile(string $path, ?string $charset) : void
    {
        if (is_dir($path)) {
            throw new DirectoryException("directory: ". $path);
        }
        if (!file_exists($path)) {
            throw new HttpException(HttpStatus::NOT_FOUND, $path);
        }

        $fileSize = filesize($path);
        if ($fileSize === false) $fileSize = 0;

        $mtype = null;
        $pos = strrpos($path, '.');
        if ($pos !== false) {
            $ext = strtolower(substr($path, $pos + 1));
            $mtype = Mimes::type($ext);
        }
        if ($mtype === null)
            $mtype = "application/octet-stream";
        if (StringUtil::startsWith($mtype, "text/") && $charset !== null)
            $mtype = $mtype. "; charset=". $charset;

        $this->headers->setContentType($mtype);
        $this->headers->setContentLength($fileSize);
        $this->sendResHeaders(Tour::TOUR_ID_NOCHECK);

        // PHP: open file non-blocking and pump through SendFileShip.
        $infile = @fopen($path, "rb");
        if ($infile === false) {
            throw new HttpException(HttpStatus::INTERNAL_SERVER_ERROR, $path);
        }
        stream_set_blocking($infile, false);
        $rd = new StreamRudder($infile);

        $bufsize = $this->tour->ship->protocolHandler->maxResPacketDataSize();
        $agt = GrandAgent::get($this->tour->ship->agentId);

        // Mirrors Java TourRes.sendFile's switch on
        // BayServer.harbor.fileMultiplexer(). PHP only implements
        // Spider and Spin multiplexers (Job / Taxi / Pigeon are
        // thread-based and don't fit PHP's process-per-agent model);
        // BuiltInHarborDocker validates the config and falls back to
        // the Spider default for unsupported types, so this switch
        // only needs to handle Spider and Spin in practice.
        switch (BayServer::$harbor->fileMultiplexer()) {
            case Harbor::MULTIPLEXER_TYPE_SPIN:
                $mpx = $agt->spinMultiplexer;
                break;
            case Harbor::MULTIPLEXER_TYPE_SPIDER:
            default:
                $mpx = $agt->spiderMultiplexer;
                break;
        }

        $sendFileShip = new SendFileShip();
        $tp = new MultiplexerPlainTransporter($mpx, $sendFileShip, true, $bufsize, false);
        $sendFileShip->initSendFile($rd, $tp, $this->tour);
        $sid = $sendFileShip->shipId;
        $this->setConsumeListener(function ($len, $resume) use ($sendFileShip, $sid) {
            if ($resume) {
                $sendFileShip->resumeRead($sid);
            }
        });

        $st = RudderStateStore::getStore($agt->agentId)->rent();
        $st->init($rd, $tp);
        $mpx->addRudderState($rd, $st);
        $mpx->reqRead($rd);
    }

    /**
     * Direct Boarding transfer entry point. PHP forwards via the ship
     * -> handler -> multiplexer chain; the SpiderMultiplexer override
     * throws Sink, so this method exists to keep the wiring intact
     * even though no working transfer path is reachable.
     */
    public function transferContent(int $checkId, Rudder $fileRd, int $ofs, int $len) : void
    {
        BayLog::debug("%s transfer content: ofs=%d len=%d", $this, $ofs, $len);
        // Callback receives the post-consumption bufferAvailable flag
        // and forwards it to the tour consume listener.
        $lis = function ($avail = true) use ($checkId, $len) {
            if ($this->resConsumeListener !== null)
                ($this->resConsumeListener)($len, $avail);
        };

        if ($this->tour->isZombie()) {
            BayLog::debug("%s zombie tour. return", $this);
            $lis(true);
            return;
        }
        if (!$this->headerSent)
            throw new Sink("Header not sent");

        $this->bytesPosted += $len;

        if ($this->tour->isAborted()) {
            BayLog::debug("%s Aborted tour. do nothing", $this);
            $this->tour->changeState($checkId, Tour::STATE_ENDED);
            $lis();
        }
        else {
            try {
                $this->tour->ship->transferResContent($this->tour->shipId, $this->tour, $fileRd, $ofs, $len, $lis);
            }
            catch (\Throwable $e) {
                BayLog::debug_e($e, "%s error on transferContent", $this);
                $lis();
                $this->tour->changeState(Tour::TOUR_ID_NOCHECK, Tour::STATE_ABORTED);
                throw $e;
            }
        }
    }

    public function setConsumeListener(callable $listener) : void
    {
        $this->resConsumeListener =$listener;
        $this->bytesPosted = 0;
    }


    public function sendResContent(int $checkId, string $buf, int $ofs, int $len) : bool
    {
        //$this->tour->checkTourId($checkId);
        BayLog::debug("%s sendContent len=%d cargo=%s", $this->tour, $len,
            $this->tour->cargo === null ? "null" : $this->tour->cargo);

        if ($this->tour->cargo !== null) {
            $this->tour->cargo->saveContent($buf, $ofs, $len);
        }

        // Callback: receives the post-consumption bufferAvailable flag
        // from the multiplexer and forwards it to the
        // tour-level consume listener.
        $consumed_cb = function ($avail = true) use ($len, $checkId) {
            $this->consumed($checkId, $len, $avail);
        };

        if ($this->tour->isZombie()) {
            BayLog::debug("%s zombie return", $this);
            $consumed_cb(true);
            return true;
        }

        if (!$this->headerSent)
            throw new Sink("BUG!: Header not sent");

        $this->bytesPosted += $len;
        $this->tour->ship->postResBytes($len);
        BayLog::debug("%s post res content len=%d posted=%d limit=%d",
            $this->tour, $len, $this->bytesPosted, $this->bytesLimit);

        if($this->tour->isZombie() || $this->tour->isAborted()) {
            // Don't send peer any data. Do nothing
            BayLog::debug("%s Aborted or zombie tour. do nothing: %s state=%s", $this, $this->tour, $this->tour->state);
            $consumed_cb(true);
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
                    $consumed_cb(true);
                    $this->tour->changeState(Tour::TOUR_ID_NOCHECK, Tour::STATE_ABORTED);
                    throw $e;
                }
            }
        }

        if ($this->bytesLimit > 0 && $this->bytesPosted > $this->bytesLimit) {
            throw new ProtocolException("Post data exceed content-length: {$this->bytesPosted}/{$this->bytesLimit}");
        }

        // Use ship-level resBufferAvailable so suspended sibling tours
        // on the same H2 connection share the wake-up signal: when this
        // ship's queue drains below shipBufferSize, consumeResBytes fans
        // out resume notifications to every paused tour, not just the
        // one whose WriteUnit is popped.
        return $this->tour->ship->resBufferAvailable();
    }

    public function endResContent(int $checkId) : void
    {
        //$this->tour->checkTourId($checkId);

        BayLog::debug("%s end ResContent", $this);

        if ($this->tour->isEnded()) {
            BayLog::debug("%s Tour is already ended (Ignore).", $this);
            return;
        }

        if (!$this->tour->isZombie() && $this->tour->city !== null)
            $this->tour->city->log($this->tour);

        // Seal off the cache entry so subsequent requests serve from it.
        if ($this->tour->cargo !== null) {
            $this->tour->cargo->endSave();
        }

        // send end message
        if ($this->canCompress) {
            $this->getCompressor()->finish();
        }

        // Callback (: receives bufferAvailable for parity
        // but endTour doesn't gate on back-pressure).
        $tourReturned = false;
        $callback = function ($avail = true) use ($checkId, &$tourReturned) {
            //$this->tour->checkTourId($checkId);
            $this->tour->ship->returnTour($this->tour);
            $tourReturned = true;
        };

        try {
            if($this->tour->isZombie() || $this->tour->isAborted()) {
                // Don't send peer any data. Only return tour
                BayLog::debug("%s Aborted or zombie tour. do nothing: %s state=%s", $this, $this->tour, $this->tour->state);
                $callback(true);
            }
            else {
                try {
                    $this->tour->ship->sendEndTour($this->tour->shipId, $this->tour, $callback);
                }
                catch(IOException $e) {
                    BayLog::debug("%s Error on sending end tour", $this);
                    $callback(true);
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
        //$this->tour->checkTourId($checkId);

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

    /**
     * Multiplexer's write-completion callback chain ends here.
     * Delegates to InboundShip::consumeResBytes so that the ship-level
     * F→T transition can resume every paused sibling tour. The
     * $bufferAvailable parameter from the WriteUnit only describes the
     * rudder state at the moment this specific unit was popped, which
     * is not enough on its own when many H2 tours share one rudder.
     */
    private function consumed(int $checkId, int $length, bool $bufferAvailable) : void
    {
        //$this->tour->checkTourId($checkId);
        // The listener can be cleared between when the write was queued
        // and when it completes (H2 multiplexes many streams on one
        // connection, and a stream reset or finalize clears it). Treat
        // null as a no-op rather than a Sink that would tear down the
        // whole agent.
        if ($this->resConsumeListener === null) {
            BayLog::debug("%s resConsumed (no listener): len=%d", $this->tour, $length);
            return;
        }

        BayLog::debug("%s resConsumed: len=%d avail=%b", $this->tour, $length, $bufferAvailable);

        $this->tour->ship->consumeResBytes($length);
    }
}

