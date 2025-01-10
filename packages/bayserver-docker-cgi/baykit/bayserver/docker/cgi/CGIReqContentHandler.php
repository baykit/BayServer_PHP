<?php

namespace baykit\bayserver\docker\cgi;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\multiplexer\PlainTransporter;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\common\Multiplexer;
use baykit\bayserver\common\Postpone;
use baykit\bayserver\common\RudderState;
use baykit\bayserver\docker\Harbor;
use baykit\bayserver\HttpException;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\rudder\StreamRudder;
use baykit\bayserver\Sink;
use baykit\bayserver\tour\ReqContentHandler;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\SysUtil;


class CGIReqContentHandler implements ReqContentHandler, Postpone
{
    const READ_CHUNK_SIZE = 8192;
    
    public CGIDocker $cgiDocker;
    public ?Tour $tour;
    public int $tourId;
    public bool $available;
    public $process;
    public int $pid;
    public Rudder $stdInRd;
    public Rudder $stdOutRd;
    public ?Rudder $stdErrRd = null;
    public bool $stdOutClosed;
    public bool $stdErrClosed;
    public int $lastAccess;
    public ?Multiplexer $multiplexer = null;
    private array $buffers = [];
    private array $env = [];

    public function __construct(CGIDocker $dkr, Tour $tur, array &$env)
    {
        $this->cgiDocker = $dkr;
        $this->tour = $tur;
        $this->tourId = $tur->id();
        $this->stdOutClosed = true;
        $this->stdErrClosed = true;
        $this->lastAccess = 0;
        $this->env = $env;
    }


    //////////////////////////////////////////////////////
    // Implements ReqContentHandler
    //////////////////////////////////////////////////////

    public function onReadReqContent(Tour $tur, string $buf, int $start, int $len, ?callable $callback): void
    {
        BayLog::info("%s CGITask:onReadContent: start=%d len=%d", $tur, $start, $len);
        if($this->process != null) {
            $this->writeToStdIn($tur, $buf, $start, $len, $callback);
        }
        else {
            // postponed
            $this->buffers[] = [array_slice($buf, start, len), $callback];
        }
        $this->access();
    }

    public function onEndReqContent(Tour $tur): void
    {
        BayLog::trace("%s CGITask:endReqContent", $tur);
        $this->access();
    }

    public function onAbortReq(Tour $tur): bool
    {
        BayLog::debug("%s CGI:abortReq", $tur);

        if(!$this->stdOutClosed && $this->multiplexer != null) {
            $this->multiplexer->reqClose($this->stdOutRd);
        }
        if(!$this->stdErrClosed && $this->multiplexer != null) {
            $this->multiplexer->reqClose($this->stdErrRd);
        }

        if($this->process == null) {
            BayLog::debug("%s Cannot kill process (pid is null)", $tur);
        }
        else {
            BayLog::debug("%s KILL PROCESS!: %s", $tur, $this->pid);
            proc_terminate($this->process, SIGKILL);
        }

        return false;  # not aborted immediately
    }

    //////////////////////////////////////////////////////
    // Implements Postpone
    //////////////////////////////////////////////////////

    public function run(): void
    {
        $this->cgiDocker->subWaitCount();
        BayLog::info("%s challenge postponed tour", $this->tour, $this->cgiDocker->getWaitCount());
        $this->reqStartTour();
    }

    //////////////////////////////////////////////////////
    // Other Methods
    //////////////////////////////////////////////////////

    public function reqStartTour() : void
    {
        if($this->cgiDocker->addProcessCount()) {
            BayLog::debug("%s start tour: wait count=%d", $this->tour, $this->cgiDocker->getWaitCount());
            $this->startTour();
        }
        else {
            BayLog::warn("%s Cannot start tour: wait count=%d", $this->tour, $this->cgiDocker->getWaitCount());
            $agt = GrandAgent::get($this->tour->ship->agentId);
            $agt->addPostpone($this);
        }
    }

    public function startTour() : void
    {
        $this->available = false;

        $fds = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => SysUtil::runOnWindows() ? STDERR : array("pipe", "w")
        );
        $cmdArgs = $this->cgiDocker->createCommand($this->env);
        BayLog::debug("Spawn: %s", $cmdArgs);

        $this->process = proc_open($cmdArgs, $fds, $pips, null, $this->env);
        if($this->process === false)
            throw new HttpException(HttpStatus::INTERNAL_SERVER_ERROR, "Cannot open process: %s", $cmdArgs);

        $stat = proc_get_status($this->process);
        $this->pid = $stat["pid"];
        if(!$stat["running"]) {
            throw new HttpException(HttpStatus::INTERNAL_SERVER_ERROR, "Cannot open process: %s: exit code=%s", $cmdArgs, $stat["exitcode"]);
        }

        $stdIn = $pips[0];
        $stdOut = $pips[1];
        $this->stdInRd = new StreamRudder($stdIn);
        $this->stdOutRd = new StreamRudder($stdOut);
        if(!SysUtil::runOnWindows()) {
            $stdErr = $pips[2];
            $this->stdErrRd = new StreamRudder($stdErr);
        }

        BayLog::debug("PID: %d", $this->pid);
        BayLog::debug("STDIN: %s", $this->stdInRd);
        BayLog::debug("STDOUT: %s", $this->stdOutRd);
        if($this->isStderrEnabled())
            BayLog::debug("STDERR: %s", $this->stdErrRd);

        $this->stdOutClosed = false;
        $this->stdErrClosed = $this->isStderrEnabled() ? false: true;


        $bufsize = $this->tour->ship->protocolHandler->maxResPacketDataSize();

        $fname = "cgi#" . $this->pid;

        $agt = GrandAgent::get($this->tour->ship->agentId);

        switch(BayServer::$harbor->cgiMultiplexer()) {
            case Harbor::MULTIPLEXER_TYPE_SPIN: {
                throw new Sink();
            }

            case Harbor::MULTIPLEXER_TYPE_SPIDER: {
                stream_set_blocking($this->stdOutRd->key(), false);
                if($this->stdErrRd != null)
                    stream_set_blocking($this->stdErrRd->key(), false);

                $mpx = $agt->spiderMultiplexer;
                break;
            }

            default:
                throw new IOException("Multiplexer not supported: %d", BayServer::$harbor->cgiMultiplexer());
        }

        $outShip = new CGIStdOutShip();
        $outTp = new PlainTransporter($mpx, $outShip, false, $bufsize, false);
        $outTp->init();
        $outShip->initOutShip($this->stdOutRd, $this->tour->ship->agentId, $this->tour, $outTp, $this);

        $mpx->addRudderState($this->stdOutRd, new RudderState($this->stdOutRd, $outTp));

        $sid = $outShip->shipId;
        $this->tour->res->setConsumeListener(function ($len, $resume) use ($outShip, $sid){
            if($resume)
                $outShip->resumeRead($sid);
        });

        $mpx->reqRead($this->stdOutRd);

        if($this->stdErrRd != null) {
            $errShip = new CGIStdErrShip();
            $errTp = new PlainTransporter($mpx, $errShip, false, $bufsize, false);
            $errTp->init();
            $errShip->initErrShip($this->stdErrRd, $this->tour->ship->agentId, $this);

            $mpx->addRudderState($this->stdErrRd, new RudderState($this->stdErrRd, $errTp));
            $mpx->reqRead($this->stdErrRd);
        }

        $this->access();
    }


    public function closePipes() : void
    {
        fclose($this->stdInRd->key());
        fclose($this->stdOutRd->key());
        if($this->isStderrEnabled())
            fclose($this->stdErrRd->key());
        $this->stdOutClosed();
        $this->stdErrClosed();
    }

    public function stdOutClosed() : void
    {
        $this->stdOutClosed = true;
        if($this->stdOutClosed && $this->stdErrClosed)
            $this->processFinished();
    }

    public function stdErrClosed() : void
    {
        $this->stdErrClosed = true;
        if($this->stdOutClosed && $this->stdErrClosed)
            $this->processFinished();
    }

    public function isStderrEnabled() : bool {
        return $this->stdErrRd !== null;
    }

    public function access() : void {
        $this->lastAccess = time();
    }

    public function timedOut() : bool
    {
        if($this->cgiDocker->timeoutSec <= 0)
            return false;

        $durationSec = time() - $this->lastAccess;
        BayLog::debug("%s Check CGI timeout: dur=%d, timeout=%d", $this->tour, $durationSec, $this->cgiDocker->timeoutSec);
        return $durationSec > $this->cgiDocker->timeoutSec;
    }

    private function writeToStdIn(Tour $tur, string $buf, int $start, int $len, ?callable $callback)
    {
        $wroteLen = fwrite($this->stdInRd->key(), substr($buf, $start, $start + $len));
        fflush($this->stdInRd->key());
        BayLog::debug("%s stdin wrote=%d", $tur, $wroteLen);
        $tur->req->consumed(Tour::TOUR_ID_NOCHECK, $len, $callback);
    }

    private function processFinished() : void
    {
        BayLog::debug("%s processFinished pid=%d", $this->tour, $this->pid);

        $code = proc_close($this->process);

        $agtId = $this->tour->ship->agentId;
        try {
            if($code != 0) {
                // Exec failed
                BayLog::error("%s CGI Exec error pid=%d code=%d", $this->tour, $this->pid, $code & 0xff);
                $this->tour->res->sendError($this->tourId, HttpStatus::INTERNAL_SERVER_ERROR, "Invalid exit status");
            }
            else {
                $this->tour->res->endResContent($this->tourId);
            }
        }
        catch(IOException $e) {
            BayLog::error_e($e);
        }

        $this->cgiDocker->subProcessCount();
        if($this->cgiDocker->getWaitCount() > 0) {
            BayLog::warn("agt#%d Catch up postponed process: process wait count=%d", $agtId, $this->cgiDocker->getWaitCount());
            $agt = GrandAgent::get($agtId);
            $agt->reqCatchUp();
        }
    }
}

