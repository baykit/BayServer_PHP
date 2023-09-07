<?php

namespace baykit\bayserver\agent\transporter;

use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\agent\SpinHandler;
use baykit\bayserver\agent\SpinHandler_SpinListener;
use baykit\bayserver\BayLog;
use baykit\bayserver\util\ArrayUtil;
use baykit\bayserver\util\Postman;
use baykit\bayserver\util\Reusable;
use baykit\bayserver\util\Valve;

class SpinWriteTransporter implements SpinHandler_SpinListener, Reusable, Valve, Postman
{
    public $spinHandler;
    public $dataListener;
    public $outFile;
    public $writeQueue = [];

    public function __construct()
    {
    }

    public function init(SpinHandler $hnd, $outFile, DataListener $lis) : void
    {
        $this->spinHandler = $hnd;
        $this->dataListener = $lis;
        $this->outFile = $outFile;
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
        $this->outFile = null;
    }



    //////////////////////////////////////////////////////
    // implements SpinHandler_SpinListener
    //////////////////////////////////////////////////////

    public function lap() : array
    {
        try {

            $buf = "";
            if (count($this->writeQueue) == 0) {
                BayLog::warn("%s Write queue empty", $this);
                return [NextSocketAction::SUSPEND, false];
            }

            $buf = $this->writeQueue[0];

            $length = fwrite($this->outFile, $buf);

            if ($length == 0)
                return [NextSocketAction::CONTINUE, true];
            elseif ($length < strlen($buf)) {
                $buf = substr($buf, $length + 1);
                return [NextSocketAction::CONTINUE, true];
            }

            ArrayUtil::removeByIndex(0, $this->writeQueue);
            if (count($this->writeQueue) == 0)
                return [NextSocketAction::SUSPEND, false];
            else
                return [NextSocketAction::CONTINUE, true];

        } catch (\Exception $e) {
            BayLog::error_e($e);
            $this->close();
            return [NextSocketAction::CLOSE, false];
        }
    }

    public function checkTimeout(int $durationSec): bool
    {
        return false;
    }

    public function close() : void
    {
        if($this->outFile !== null) {
            fclose($this->outFile);
        }
    }

    //////////////////////////////////////////////////////
    // Implements Valve
    //////////////////////////////////////////////////////


    public function openValve(): void
    {
        $this->spinHandler->askToCallBack($this);
    }


    //////////////////////////////////////////////////////
    // Implements Postman
    //////////////////////////////////////////////////////

    public function post(string $buf, ?array $adr, $tag, ?callable $lis) : void
    {
        $empty = (count($this->writeQueue) == 0);
        $this->writeQueue[] = $buf;
        if ($empty) {
            $this->openValve();
        }
    }


    public function flush(): void
    {
    }

    public function postEnd(): void
    {
    }

    public function isZombie(): bool
    {
        return false;
    }

    public function abort(): void
    {
    }
}