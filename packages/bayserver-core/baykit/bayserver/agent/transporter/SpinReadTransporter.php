<?php

namespace baykit\bayserver\agent\transporter;

use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\agent\SpinHandler_SpinListener;
use baykit\bayserver\BayLog;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\SysUtil;
use baykit\bayserver\util\Valve;

class SpinReadTransporter implements SpinHandler_SpinListener, Valve
{
    public $spinHandler;
    public $dataListener;
    public $inFD;
    public $fileLen;
    public $readLen;
    public $totalRead;
    public $timeoutSec;
    public $eofChecker;
    public $isClosed;

    public function __construct(int $bufsize)
    {
        $this->readLen = $bufsize;
    }

    public function init($spinHnd, $listener, $inFD, $limit, $timeoutSec, $eofChecker): void
    {
        $this->spinHandler = $spinHnd;
        $this->dataListener = $listener;
        $this->inFD = $inFD;
        #$ret = stream_set_blocking($this->inFD, false);
        #if($ret === false)
        #    throw new IOException(SysUtil::lastErrorMessage());
        $this->fileLen = $limit;
        $this->totalRead = 0;
        $this->timeoutSec = $timeoutSec;
        $this->eofChecker = $eofChecker;
        $this->isClosed = false;
    }

    public function __toString(): string
    {
        return strval($this->dataListener);
    }

    //////////////////////////////////////////////////////
    // implements Reusable
    //////////////////////////////////////////////////////

    public function reset(): void
    {
        $this->dataListener = null;
        $this->inFD = null;
    }



    //////////////////////////////////////////////////////
    // implements SpinHandler_SpinListener
    //////////////////////////////////////////////////////

    public function lap()
    {
        try {
            $buf = fread($this->inFD, $this->readLen);

            $eof = false;
            if($buf === false) {
                BayLog::error(SysUtil::lastErrorMessage());
                $eof = true;
            }
            elseif (strlen($buf) == 0) {
                if (feof($this->inFD)) {
                    $eof = true;
                } else {
                    # Data not reached
                    return [NextSocketAction::CONTINUE, true];
                }
            }

            if(!$eof) {
                $this->totalRead += strlen($buf);

                $nextAct = $this->dataListener->notifyRead($buf);

                if ($this->fileLen > 0 && $this->totalRead == $this->fileLen)
                    $eof = true;
            }

            if($eof) {
                $this->dataListener->notifyEof();
                $this->close();
                return [NextSocketAction::CLOSE, false];
            }

            return [$nextAct, false];
        } catch (\Exception $e) {
            BayLog::error_e($e);
            $this->close();
            return [NextSocketAction::CLOSE, false];
        }
    }

    public function checkTimeout(int $durationSec)
    {
        return $durationSec > $this->timeoutSec;
    }

    public function close() : void
    {
        if($this->inFD !== null) {
            fclose($this->inFD);
        }
        $this->dataListener->notifyClose();
        $this->isClosed = true;
    }

    //////////////////////////////////////////////////////
    // Implements Valve
    //////////////////////////////////////////////////////


    public function openValve(): void
    {
        $this->spinHandler->askToCallBack($this);
    }


    //////////////////////////////////////////////////////
    // Custom methods
    //////////////////////////////////////////////////////



}