<?php

namespace baykit\bayserver\agent\transporter;

use baykit\bayserver\agent\ChannelListener;
use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\agent\UpgradeException;
use baykit\bayserver\BayLog;
use baykit\bayserver\Sink;
use baykit\bayserver\util\ArrayUtil;
use baykit\bayserver\util\EofException;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\Postman;
use baykit\bayserver\util\Reusable;
use baykit\bayserver\util\SysUtil;
use baykit\bayserver\util\Valve;
use Cassandra\Exception\ProtocolException;

class WriteUnit
{
    public $buf;
    public $adr;
    public $tag;
    public $listener;

    public function __construct($buf, $adr, $tag, $listener)
    {
        $this->buf = $buf;
        $this->adr = $adr;
        $this->tag = $tag;
        $this->listener = $listener;
    }

    public function done() : void
    {
        if($this->listener !== null)
            ($this->listener)();
    }
}


abstract class Transporter implements ChannelListener, Reusable, Valve, Postman
{
    public $serverMode;
    public $traceSsl;
    public $dataListener;
    public $ch;
    public $writeQueue = [];
    public $finale;
    public $initialized;
    public $chValid;
    public $socketIo;
    public $handshaked;
    public $capacity;
    public $nonBlockingHandler;
    private $wtOnly;

    public abstract function secure() : bool;
    //public abstract function handshakeNonblock();
    //public abstract function handshakeFinished();
    public abstract function readNonblock() : array;
    public abstract function writeNonblock(string $buf, $adr) : int;

    public function __construct(bool $serverMode, int $bufsize, bool $traceSsl, bool $wtOnly = false)
    {
        $this->serverMode = $serverMode;
        $this->capacity = $bufsize;
        $this->traceSsl = $traceSsl;
        $this->wtOnly = $wtOnly;
        $this->reset();
    }

    public function __toString() : string
    {
        return "tpt[{$this->dataListener}]";
    }

    public function init($chHnd, $ch, $lis) : void
    {
        if ($this->initialized) {
            BayLog::error("%s This transporter is already in use by channel: %s", $this, $this->ch);
            throw new Sink("IllegalState");
        }
        if (count($this->writeQueue) != 0)
            throw new Sink();

        $this->nonBlockingHandler = $chHnd;
        $this->dataListener = $lis;
        $this->ch = $ch;
        $this->initialized = true;
        $this->setValid(true);
        $this->handshaked = false;
        $this->nonBlockingHandler->addChannelListener($ch, $this);
    }

    public function abort() : void
    {
        BayLog::debug("%s abort", $this);
        $this->nonBlockingHandler->askToClose($this->ch);
    }

    public function isZombie() : bool
    {
        return $this->ch !== null && !$this->chValid;
    }

    /////////////////////////////////////////////////////////////////////////////////
    // implements Reusable
    /////////////////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        # Check write queue
        if (count($this->writeQueue) > 0)
            throw new Sink("Write queue is not empty");

        BayLog::debug("Reset transporter ch=%d", $this->ch);
        $this->finale = false;
        $this->initialized = false;
        $this->ch = null;
        $this->setValid(false);
        $this->handshaked = false;
        $this->socketIo = null;
    }

    /////////////////////////////////////////////////////////////////////////////////
    // implements ChannelListener
    /////////////////////////////////////////////////////////////////////////////////

    public function onReadable($chkCch) : int
    {
        $this->checkChannel($chkCch);
        BayLog::debug("%s onReadable", $this);

        if (!$this->handshaked) {
            $this->handshakeNonblock();
            BayLog::debug("%s Handshake done", $this->dataListener);
            $this->handshakeFinished();
            $this->handshaked = true;
        }

        try {
            list($read_buf, $adr) = $this->readNonblock();
        }
        catch(EofException $e) {
            BayLog::debug("%s EOF", $this);
            $this->set_Valid(false);
            return $this->dataListener->notifyEeof();
        }

        BayLog::debug("%s read %d bytes", $this, strlen($read_buf));
        if (strlen($read_buf) == 0)
            return $this->dataListener->notifyEof();

        try {
            try {
                $next_action = $this->dataListener->notifyRead($read_buf, $adr);
                if ($next_action === null) {
                    throw new Sink();
                }
                BayLog::debug("%s returned from notify_read(). next action=%d", $this->dataListener, $next_action);
                return $next_action;
            } catch (UpgradeException $e) {
                BayLog::debug("%s Protocol upgrade", $this->dataListener);
                return $this->dataListener->notifyRead($read_buf, $adr);
            }
        }
        catch(ProtocolException $e) {
            $close = $this->dataListener->notifyProtocolError($e);

            if (!$close && $this->serverMode)
                return NextSocketAction::CONTINUE;
            else
                return NextSocketAction::CLOSE;
        }
    }

    public function onWritable($chkCh) : int
    {
        $this->checkChannel($chkCh);

        BayLog::trace("%s Writable", $this);

        if (!$this->chValid)
            return NextSocketAction::CLOSE;

        if (!$this->handshaked) {
            $this->handshakeNonblock();
            BayLog::debug("%s Handshake: done", $this->dataListener);
            $this->handshakeFinished();
            $this->handshaked = true;
        }

        $empty = false;
        while (true) {
            # BayLog::debug "#{$this} Send queue len=#{@write_queue.length}"
            $wunit = null;

            if (count($this->writeQueue) == 0) {
                $empty = true;
                break;
            }
            $wunit = $this->writeQueue[0];

            if ($empty)
                break;

            BayLog::debug("%s Try to write: pkt=%s buflen=%d ch=%s chValid=%s", $this, $wunit->tag,
                strlen($wunit->buf), $this->ch, $this->chValid);
            #BayLog::info("buf=%s", wunit.buf)

            if ($this->chValid && strlen($wunit->buf) > 0) {
                $len = $this->writeNonblock($wunit->buf, $wunit->adr);
                BayLog::debug("%s write %d bytes", $this, $len);
                $wunit->buf = substr($wunit->buf, $len);

                if (strlen($wunit->buf) > 0)
                    # Data remains
                    break;
            }

            # packet send complete
            $wunit->done();

            if (count($this->writeQueue) == 0)
                throw new Sink("%s Write queue is empty", $this);
            ArrayUtil::removeByIndex(0, $this->writeQueue);
            $empty = (count($this->writeQueue) == 0);

            if ($empty)
                break;
        }

        if ($empty) {
            if ($this->finale) {
                BayLog::trace("%s finale return Close", $this);
                $state = NextSocketAction::CLOSE;
            }
            elseif ($this->wtOnly) {
                $state = NextSocketAction::SUSPEND;
            }
            else {
                $state = NextSocketAction::READ;
            }
        }
        else
            $state = NextSocketAction::CONTINUE;

        return $state;
    }

    public function onConnectable($chkCh) : int
    {
        $this->checkChannel($chkCh);
        BayLog::trace("%s onConnectable (^o^)/: ch=%s", $this, $chkCh);

        # check connection by sending 0 bytes data.
        $success = stream_socket_sendto($this->ch, "");
        if ($success === false || $success == -1) {
            BayLog::error("Connect failed: %s", SysUtil::lastErrorMessage());
            return NextSocketAction::CLOSE;
        }
        return $this->dataListener->notifyConnect();
    }

    public function onError($chkCh, $err) : void
    {
        $this->checkChannel($chkCh);
        BayLog::trace("%s onError: %s", $this, $err);
        BayLog::error_e($err);
    }

    public function onClosed($chkCh) : void
    {
        try {
            $this->checkChannel($chkCh);
        }
        catch(\Exception $e) {
            BayLog::error_e($e);
            return;
        }

        $this->setValid(false);

        # Clear queue
        foreach ($this->writeQueue as $wunit)
                $wunit->done();
        $this->writeQueue = [];

        $this->dataListener->notifyClose();
    }

    public function checkTimeout($chkCh, $durationSec) : bool
    {
        $this->checkChannel($chkCh);

        return $this->dataListener->checkTimeout($durationSec);
    }

    /////////////////////////////////////////////////////////////////////////////////
    // implements Postman
    /////////////////////////////////////////////////////////////////////////////////
    public function post(string $buf, ?array $adr, $tag, ?callable $lis): void
    {
        $this->checkInitialized();

        BayLog::debug("%s post: %s len=%d", $this, $tag, strlen($buf));

        if (!$this->chValid)
            throw new IOException("{$this} Channel is invalid, Ignore");
        else {
            $unt = new WriteUnit($buf, $adr, $tag, $lis);
            $this->writeQueue[] = $unt;

            BayLog::trace("%s post %s->askToWrite", $this, $tag);
            $this->nonBlockingHandler->askToWrite($this->ch);
        }
    }

    /////////////////////////////////////////////////////////////////////////////////
    // implements Valve
    /////////////////////////////////////////////////////////////////////////////////

    public function openValve(): void
    {
        BayLog::debug("%s resume", $this);
        $this->nonBlockingHandler->askToRead($this->ch);
    }


    /////////////////////////////////////////////////////////////////////////////////
    // Other methods
    /////////////////////////////////////////////////////////////////////////////////

    public function flush() : void
    {
        $this->checkInitialized();

        BayLog::debug("%s flush", $this);

        if($this->chValid) {
            $empty = count($this->writeQueue) == 0;

            if (!$empty) {
                BayLog::debug("%s flush->askToWrite", $this);
                $this->nonBlockingHandler->askToWrite($this->ch);
            }
        }
    }

    public function postEnd() : void
    {
        $this->checkInitialized();

        BayLog::debug("%s postEnd vld=%s", $this, $this->chValid);

        // setting order is QUITE important  finalState->finale
        $this->finale = true;

        if($this->chValid) {
            $empty = count($this->writeQueue) == 0;

            if (!$empty) {
                BayLog::debug("%s postEnd->askToWrite", $this);
                $this->nonBlockingHandler->askToWrite($this->ch);
            }
        }
    }

    private function checkChannel($chk_ch) : void
    {
        $this->checkInitialized();
        if ($chk_ch != $this->ch)
            throw new Sink("Invalid transporter instance (ships was returned?): {$chk_ch}");
    }

    private function checkInitialized() : void
    {
        if (!$this->initialized)
            throw new Sink("Illegal State");
    }

    private function setValid($valid) : void
    {
        $this->chValid = $valid;
    }
}
