<?php

namespace baykit\bayserver\docker\cgi;

use baykit\bayserver\BayLog;
use baykit\bayserver\common\Multiplexer;
use baykit\bayserver\HttpException;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\rudder\StreamRudder;
use baykit\bayserver\tour\ReqContentHandler;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\SysUtil;


class CGIReqContentHandler implements ReqContentHandler
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

    public function __construct(CGIDocker $dkr, Tour $tur)
    {
        $this->cgiDocker = $dkr;
        $this->tour = $tur;
        $this->tourId = $tur->id();
        $this->stdOutClosed = true;
        $this->stdErrClosed = true;
        $this->lastAccess = 0;
    }


    //////////////////////////////////////////////////////
    // Implements ReqContentHandler
    //////////////////////////////////////////////////////

    public function onReadReqContent(Tour $tur, string $buf, int $start, int $len, ?callable $callback): void
    {
        BayLog::info("%s CGITask:onReadContent: start=%d len=%d", $tur, $start, $len);

        $wroteLen = fwrite($this->stdInRd->key(), substr($buf, $start, $start + $len));
        fflush($this->stdInRd->key());

        BayLog::info("%s CGITask:onReadContent: wrote=%d", $tur, $wroteLen);
        $tur->req->consumed(Tour::TOUR_ID_NOCHECK, $len, $callback);
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
                $this->tour->res->endResContent($this->tourId);
            }
        }
        catch(IOException $e) {
            BayLog::error_e($e);
        }
    }
}

