<?php
namespace baykit\bayserver\tour;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\LifecycleListener;
use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\Sink;
use baykit\bayserver\util\Valve;
use baykit\bayserver\watercraft\Yacht;


class SendFileYacht extends Yacht
{

    public $file_name;
    public $fileLen;
    public $fileWroteLen;

    public $tour;
    public $tourId;

    public function __construct()
    {
        $this->reset();
    }

    public function __toString() : string
    {
        return "fyacht#{$this->yachtId}/{$this->objectId} tour={$this->tour} id={$this->tourId}";
    }

    ////////////////////////////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        $this->fileWroteLen = 0;
        $this->tourId = 0;
        $this->fileLen = 0;
        $this->tour = null;
    }

    ////////////////////////////////////////////////////////////////////
    // Implements Yacht
    ////////////////////////////////////////////////////////////////////

    public function notifyRead(string $buf, ?array $adr): int
    {
        $this->fileWroteLen += strlen($buf);
        BayLog::debug("%s read file %d bytes: total=%d/%d", $this, strlen($buf), $this->fileWroteLen, $this->fileLen);
        $available = $this->tour->res->sendContent($this->tourId, $buf, 0, strlen($buf));

        if($available) {
            return NextSocketAction::CONTINUE;
        }
        else {
            return NextSocketAction::SUSPEND;
        }
    }

    public function notifyEof(): int
    {
        BayLog::debug("%s EOF(^o^) %s", $this, $this->file_name);
        $this->tour->res->endContent($this->tourId);
        return NextSocketAction::CLOSE;
    }

    public function notifyClose(): void
    {
        BayLog::debug("File closed: %s", $this->file_name);
    }

    public function checkTimeout(int $durationSec): bool
    {
        throw new Sink();
    }

    ////////////////////////////////////////////////////////////////////
    // Custom methods
    ////////////////////////////////////////////////////////////////////

    public function init(Tour $tur, string $fname, int $flen, Valve $tp) : void
    {
        $this->initYacht();
        $this->tour = $tur;
        $this->tourId = $tur->tourId;
        $this->file_name = $fname;
        $this->fileLen = $flen;
        $this->tour->res->setConsumeListener(function ($len, $resume) use ($tp) {
            if($resume) {
                $tp->valveOpen();
            }
        });
    }
}
