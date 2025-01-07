<?php
namespace baykit\bayserver\docker\cgi;

use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\common\ReadOnlyShip;
use baykit\bayserver\common\Transporter;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\Sink;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\Valve;
use baykit\bayserver\watercraft\Yacht;


class CGIStdOutShip extends ReadOnlyShip
{
    private int $fileWroteLen = 0;

    private ?Tour $tour;
    private int $tourId;

    private string $tmpBuf = "";
    private int $curPos = 0;
    private bool $headerReading = false;
    private ?CGIReqContentHandler $handler;

    public function __toString() : string
    {
        return "agt#{$this->agentId} out_ship#{$this->shipId}/{$this->objectId}";
    }

    public function initOutShip(Rudder $rd, int $agtId, Tour $tur, Transporter $tp, CGIReqContentHandler $handler) : void
    {
        parent::init($agtId, $rd, $tp);
        $this->handler = $handler;
        $this->tour = $tur;
        $this->tourId = $tur->tourId;
        $this->headerReading = true;
    }

    /////////////////////////////////////
    // Implements Reusable
    /////////////////////////////////////

    public function reset(): void
    {
        parent::reset();
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

    public function notifyRead(string $buf): int
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
                    $this->tour->res->sendResHeaders($this->tourId);
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
        if(!$this->headerReading) {
            try {
                $available = $this->tour->res->sendResContent($this->tourId, $buf, 0, strlen($buf));
            }
            catch(IOException $e) {
                $this->notifyError($e);
                return NextSocketAction::CLOSE;
            }
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
        $this->handler->stdOutClosed();
    }

    public function notifyError(\Exception $e): void
    {
        BayLog::debug($e, "%s CGI notifyError", $this);
    }

    public function checkTimeout(int $durationSec): bool
    {
        BayLog::debug("%s Check StdOut timeout: dur=%d", $this, $durationSec);

        if($this->handler->timedOut()) {
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


}
