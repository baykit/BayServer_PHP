<?php
namespace baykit\bayserver\docker\cgi;

use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\Sink;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\Valve;
use baykit\bayserver\watercraft\Yacht;


class CGIStdOutYacht extends Yacht
{
    public $fileWroteLen;

    public $tour;
    public $tourId;

    private $tmpBuf;
    private $curPos;
    private $headerReading;
    private $handler;

    public function __construct()
    {
        $this->reset();
    }

    public function __toString() : string
    {
        return "CGIYat#{$this->yachtId}/{$this->objectId} tour={$this->tour} id={$this->tourId}";
    }

    ////////////////////////////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        $this->fileWroteLen = 0;
        $this->tourId = 0;
        $this->tour = null;
        $this->headerReading = true;
        $this->tmpBuf = "";
        $this->curPos = 0;
        $this->handler = null;
    }

    ////////////////////////////////////////////////////////////////////
    // Implements Yacht
    ////////////////////////////////////////////////////////////////////

    public function notifyRead(string $buf, ?array $adr): int
    {
        $this->fileWroteLen += strlen($buf);
        BayLog::debug("%s read file %d bytes: total=%d", $this, strlen($buf), $this->fileWroteLen);

        if ($this->headerReading) {
            $this->tmpBuf .= $buf;

            while(true) {
                $pos = strpos($this->tmpBuf, "\n", $this->curPos);
                if ($pos === false) {
                    return NextSocketAction::CONTINUE;
                }

                $line = substr($this->tmpBuf, $this->curPos, $pos - $this->curPos);
                $this->curPos = $pos + 1;

                $line = trim($line);

                #  if line is empty ("\r\n")
                #  finish header reading.
                if (StringUtil::isEmpty($line)) {
                    $this->headerReading = false;
                    $this->tour->res->sendHeaders($this->tourId);
                    $buf = substr($this->tmpBuf, $this->curPos);
                    break;
                } else {
                    $sepPos = strpos($line, ":");
                    if ($sepPos !== false) {
                        $key = trim(substr($line, 0, $sepPos));
                        $val = trim(substr($line, $sepPos + 1));
                        if (StringUtil::eqIgnorecase($key, "status"))
                            $this->tour->res->headers->status = (int)$val;
                        else
                            $this->tour->res->headers->add($key, $val);
                    }
                }
            }
        }

        $available = true;
        if(strlen($buf) >= 0) {
            $available = $this->tour->res->sendContent($this->tourId, $buf, 0, strlen($buf));
        }

        $this->handler->access();
        if($available)
            return NextSocketAction::CONTINUE;
        else
            return NextSocketAction::SUSPEND;
    }

    public function notifyEof(): int
    {
        BayLog::debug("%s CGI StdOut: EOF(^o^)", $this);
        return NextSocketAction::CLOSE;
    }

    public function notifyClose(): void
    {
        BayLog::debug("%s CGI StdOut: notifyClose", $this);
        $this->tour->req->contentHandler->stdOutClosed();
    }

    public function checkTimeout(int $durationSec): bool
    {
        BayLog::debug("%s Check StdOut timeout: dur=%d, timeout=%d", $this, $durationSec, $this->timeout);

        if($$this->handler->timedOut()) {
            // Kill cgi process instead of handing timeout
            BayLog::warn("%s Kill process!: %d", $this, $this->handler->process);
            proc_terminate($this->handler->process, SIGKILL);
            return true;
        }
        return false;
    }

    ////////////////////////////////////////////////////////////////////
    // Custom methods
    ////////////////////////////////////////////////////////////////////

    public function init(Tour $tur, Valve $vv, CGIReqContentHandler $handler) : void
    {
        $this->initYacht();
        $this->handler = $handler;
        $this->tour = $tur;
        $this->tourId = $tur->tourId;
        $this->tour->res->setConsumeListener(function ($len, $resume) use ($vv) {
            if($resume) {
                $vv->valveOpen();
            }
        });
    }

}
