<?php

namespace baykit\bayserver\docker\cgi;

use baykit\bayserver\BayLog;
use baykit\bayserver\HttpException;
use baykit\bayserver\tour\ReqContentHandler;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\SysUtil;


class CGIReqContentHandler implements ReqContentHandler
{
    const READ_CHUNK_SIZE = 8192;
    
    public $cgiDocker;
    public $tour;
    public $tourId;
    public $available;
    public $process;
    public $pid;
    public $stdIn;
    public $stdOut;
    public $stdErr = null;
    public $stdOutClosed;
    public $stdErrClosed;
    public $lastAccess;

    public function __construct(CGIDocker $dkr, Tour $tur)
    {
        $this->cgiDocker = $dkr;
        $this->tour = $tur;
        $this->tourId = $tur->id();
        $this->stdOutClosed = true;
        $this->stdErrClosed = true;
        $this->lastAccess = null;
    }


    //////////////////////////////////////////////////////
    // Implements ReqContentHandler
    //////////////////////////////////////////////////////

    public function onReadContent(Tour $tur, string $buf, int $start, int $len): void
    {
        BayLog::info("%s CGITask:onReadContent: start=%d len=%d", $tur, $start, $len);

        $wroteLen = fwrite($this->stdIn, substr($buf, $start, $start + $len));
        fflush($this->stdIn);

        BayLog::info("%s CGITask:onReadContent: wrote=%d", $tur, $wroteLen);
        $tur->req->consumed(Tour::TOUR_ID_NOCHECK, $len);
        $this->access();
    }

    public function onEndContent(Tour $tur): void
    {
        BayLog::trace("%s CGITask:endReqContent", $tur);
        $this->access();
    }

    public function onAbort(Tour $tur): bool
    {
        BayLog::debug("%s CGITask:abort", $tur);
        $this->tour->ship->agent->nonBlockingHandler->askToClose($this->stdOut);
        if($this->isStderrEnabled())
            $this->tour->ship->agent->nonBlockingHandler->askToClose($this->stdErr);

        BayLog::debug("%s KILL PROCESS!: %s", $tur, $this->pid);
        proc_terminate($this->process, SIGKILL);

        return false;  # not aborted immediately
    }


    //////////////////////////////////////////////////////
    // Other Methods
    //////////////////////////////////////////////////////

    public function startTour(array &$env) : void
    {
        $this->available = false;

        $fds = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => SysUtil::runOnWindows() ? STDERR : array("pipe", "w")
        );
        $cmdArgs = $this->cgiDocker->createCommand($env);
        BayLog::debug("Spawn: %s", $cmdArgs);

        $this->process = proc_open($cmdArgs, $fds, $pips, null, $env);
        if($this->process === false)
            throw new HttpException(HttpStatus::INTERNAL_SERVER_ERROR, "Cannot open process: %s", $cmdArgs);

        $stat = proc_get_status($this->process);
        $this->pid = $stat["pid"];
        if(!$stat["running"]) {
            throw new HttpException(HttpStatus::INTERNAL_SERVER_ERROR, "Cannot open process: %s: exit code=%s", $cmdArgs, $stat["exitcode"]);
        }

        $this->stdIn = $pips[0];
        $this->stdOut = $pips[1];
        if(!SysUtil::runOnWindows())
            $this->stdErr = $pips[2];

        BayLog::debug("PID: %d", $this->pid);
        BayLog::debug("STDIN: %d", $this->stdIn);
        BayLog::debug("STDOUT: %d", $this->stdOut);
        if($this->isStderrEnabled())
            BayLog::debug("STDERR: %d", $this->stdErr);

        $this->stdOutClosed = false;
        $this->stdErrClosed = $this->isStderrEnabled() ? false: true;
        $this->access();
    }


    public function closePipes() : void
    {
        fclose($this->stdIn);
        fclose($this->stdOut);
        if($this->isStderrEnabled())
            fclose($this->stdErr);
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
        return $this->stdErr !== null;
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

    private function processFinished() : void
    {
        BayLog::debug("%s processFinished pid=%d", $this->tour, $this->pid);

        $code = proc_close($this->process);

        try {
            if($code != 0) {
                // Exec failed
                BayLog::error("%s CGI Exec error pid=%d code=%d", $this->tour, $this->pid, $code & 0xff);
                $this->tour->res->sendError($this->tourId, HttpStatus::INTERNAL_SERVER_ERROR, "Invalid exit status");
            }
            else {
                $this->tour->res->endContent($this->tourId);
            }
        }
        catch(IOException $e) {
            BayLog::error_e($e);
        }
    }
}

