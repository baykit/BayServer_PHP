<?php

namespace baykit\bayserver\agent;


use baykit\bayserver\BayLog;
use baykit\bayserver\Sink;
use baykit\bayserver\util\ArrayUtil;
use baykit\bayserver\util\EofException;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\Selector;
use baykit\bayserver\util\SysUtil;


class ChannelState
{
    public $channel;
    public $listener;
    public $accepted = false;
    public $connecting = false;
    public $closing = false;
    public $lastAccessTime = null;

    public function __construct($ch, $lis)
    {
        $this->channel = $ch;
        $this->listener = $lis;
    }

    public function __toString()
    {
        if ($this->listener)
            $s = strval($this->listener);
        else
            $s = strval(parent);

        if ($this->closing)
            $s .= " closing=true";

        return $this->channel . " " . $s;
    }

    public function access(): void
    {
        $this->lastAccessTime = time();
    }

}

class ChannelOperation
{
    public $ch;
    public $op;
    public $connect;
    public $close;

    public function __construct($ch, $op, $connect=false, $close=false)
    {
        $this->ch = $ch;
        $this->op = $op;
        $this->connect = $connect;
        $this->close = $close;
    }
}


class NonBlockingHandler
{
    public $agent;
    public $listener = null;
    public $channelMap = [];
    public $channelCount = 0;
    public $lock;
    public $operations = [];
    public $operationsLock;

    public function __construct($agent)
    {
        $this->agent = $agent;
        #$this->lock = threading.RLock()
        $this->operations = [];
        #$this->operations_lock = threading.RLock()
    }

    public function __toString() : string
    {
        return strval($this->agent);
    }

    public function handleChannel($key)
    {
        $ch = $key->channel;
        $ch_state = $this->findChannelState($ch);
        if ($ch_state === null) {
            BayLog::error("Channel state is not registered: ch=%s", $ch);
            $this->agent->selector->unregister($ch);
            return;
        }

        BayLog::debug("%s chState=%s Waked up: operation=%d connecting=%s closing=%s",
                    $this->agent, $ch_state, $key->operation,
                    $ch_state->connecting, $ch_state->closing);

        $nextAction = null;
        try {

            if ($ch_state->closing)
                $nextAction = NextSocketAction::CLOSE;

            elseif ($ch_state->connecting) {
                $ch_state->connecting = false;
                # connectable
                $nextAction = $ch_state->listener->onConnectable($ch);
                if ($nextAction === null)
                    throw new  Sink("unknown next action");
                elseif ($nextAction == NextSocketAction::CONTINUE)
                    $this->askToRead($ch);
            } else {
                if ($key->readable()) {
                    $nextAction = $ch_state->listener->onReadable($ch);
                    if ($nextAction === null)
                        throw new  Sink("unknown next action");
                    elseif ($nextAction == NextSocketAction::WRITE)
                        $this->askToWrite($ch);
                }
                if (($nextAction != NextSocketAction::CLOSE) && $key->writable()) {
                    $nextAction = $ch_state->listener->onWritable($ch);
                    if ($nextAction === null)
                        throw new Sink("unknown next action");
                    elseif ($nextAction == NextSocketAction::READ)
                        $this->askToRead($ch);
                }
            }

            if ($nextAction === null)
                throw new Sink("unknown next action");

        }
        catch(\Exception $e) {
            if ($e instanceof EofException)
                BayLog::info("%s Socket closed by peer: skt=%s", $this->agent, $ch);
            elseif ($e instanceof IOException)
                BayLog::info("%s I/O error: %s (skt=%s)", $this->agent, $e->getMessage(), $ch);
            else
                BayLog::info("%s Unhandled error error: %s (skt=%s)", $this->agent, $e, $ch);

            # Cannot handle Exception any more
            $ch_state->listener->onError($ch, $e);
            $nextAction = NextSocketAction::CLOSE;
        }

        $cancel = false;
        $ch_state->access();
        BayLog::trace("%s next=%d chState=%s", $this->agent, $nextAction, $ch_state);
        if ($nextAction == NextSocketAction::CLOSE) {
            $this->closeChannel($ch, $ch_state);
            $cancel = false;  # already canceled in close_channel method
        }
        elseif($nextAction == NextSocketAction::SUSPEND ||
            $nextAction == NextSocketAction::READ ||
            $nextAction == NextSocketAction::WRITE) {
            $cancel = true;
        }

        elseif ($nextAction == NextSocketAction::CONTINUE) {

        }

        else {
            throw new \Exception("IllegalState:: {$nextAction}");
        }

        if ($cancel) {
            BayLog::trace("%s cancel key chState=%s", $this->agent, $ch_state);
            $this->agent->selector->unregister($ch);
        }
    }

    public function registerChannelOps()
    {
        if (count($this->operations) == 0)
            return 0;

        $nch = count($this->operations);
        foreach ($this->operations as $chOp) {
            $st = $this->findChannelState($chOp->ch);

            if (!is_resource($chOp->ch)) {
                BayLog::debug("Resource already closed");
                continue;
            }

            if($st == null && $chOp->close) {
                // already closed
                continue;
            }

            try {
                BayLog::trace("%s register op=%s chState=%s", $this->agent, self::op_mode($chOp->op), $st);
                $op = $this->agent->selector->getOp($chOp->ch);
                if ($op === null) {
                    $this->agent->selector->register($chOp->ch, $chOp->op);
                } else {
                    $newOp = $op | $chOp->op;
                    BayLog::trace("Already registered op=%s update to %s", self::op_mode($op), self::op_mode($newOp));
                    $this->agent->selector->modify($chOp->ch, $newOp);
                }

                if ($chOp->connect) {
                    if ($st === null)
                        BayLog::warn("%s register connect but ChannelState is null: %s", $this->agent, $chOp->ch);
                    else
                        $st->connecting = true;
                } elseif ($chOp->close) {
                    if ($st === null)
                        BayLog::warn("%s register close but ChannelState is null: %s", $this->agent, $chOp->ch);
                    else
                        $st->closing = true;
                }
            } catch (\Exception $e) {
                $cst = $this->findChannelState($chOp->ch);
                BayLog::error_e($e, "%s Cannot register operation: %s", $this->agent, ($cst !== null) ? $cst->listener : null);
            }
        }

        $this->operations = [];
        return $nch;
    }

    public function closeTimeoutSockets() : void
    {

    }

    public function addChannelListener($ch, $lis) : ChannelState
    {
        $ch_state = new ChannelState($ch, $lis);
        $this->addChannelState($ch, $ch_state);
        $ch_state->access();
        return $ch_state;
    }

    public function askToStart($ch) : void
    {
        BayLog::debug("%s askToStart: ch=%s", $this->agent, $ch);

        $ch_state = $this->findChannelState($ch);
        $ch_state->accepted = true;
    }

    public function askToConnect($ch, $addr) : void
    {
        BayLog::debug("%s askToStart: ch=%s", $this->agent, $ch);

        $ch_state = $this->findChannelState($ch);
        BayLog::debug("%s askToConnect addr=%s ch=%s", $this->agent, $addr, $ch);

        //$ch->connect($addr);
        $this->addOperation($ch, Selector::OP_READ, false, true);
    }


    public function askToRead($ch) : void
    {
        $ch_state = $this->findChannelState($ch);
        BayLog::debug("%s askToRead chState=%s", $this->agent, $ch_state);

        $this->addOperation($ch, Selector::OP_READ);

        if ($ch_state != false)
            $ch_state->access();
    }

    public function askToWrite($ch) : void
    {
        $st = $this->findChannelState($ch);
        BayLog::debug("%s askToWrite chState=%s", $this->agent, $st);
        $this->addOperation($ch, Selector::OP_WRITE);

        if($st === null)
            return;

        $st->access();
    }


    public function askToClose($ch) : void
    {
        $st = $this->findChannelState($ch);
        BayLog::debug("%s askToClose chState=%s", $this->agent, $st);
        $this->addOperation($ch, Selector::OP_WRITE, true);

        if($st === null)
            return;

        $st->access();
    }

    public function addOperation($ch, $op, $close=false, $connect=false) : void
    {
        if($ch == null)
            throw new Sink();

        $found = false;
        foreach ($this->operations as $ch_op) {
            if ($ch_op->ch == $ch) {
                $ch_op->op |= $op;
                $ch_op->close = $ch_op->close || $close;
                $ch_op->connect = $ch_op->connect || $connect;
                $found = true;
                BayLog::trace("%s Update operation: %s ch=%s", $this->agent, NonBlockingHandler::op_mode($ch_op->op), $ch_op->ch);
            }
        }

        if (!$found) {
            BayLog::trace("%s New operation: %s ch=%s", $this->agent, NonBlockingHandler::op_mode($op), $ch);
            $this->operations[] = new ChannelOperation($ch, $op, $connect, $close);
        }
        BayLog::trace("%s wakeup", $this->agent);
        $this->agent->wakeup();
    }

    public function closeChannel($ch, ?ChannelState $chState) : void
    {
        BayLog::debug("%s close ch=%s chState=%s", $this->agent, $ch, $chState);

        if ($chState === null)
            $chState = $this->findChannelState($ch);

        if ($chState->accepted)
            $this->agent->acceptHandler->onClosed();

        $meta = stream_get_meta_data($ch);
        $type = strtolower($meta["stream_type"]);

//        if(StringUtil::startsWith($type, "tcp_socket"))
//            $ret = stream_socket_shutdown($ch,  STREAM_SHUT_RDWR);

/*        switch($type) {
            case "stdio":
                $ret = fclose($ch);
                break;
            case "tcp_socket":
                $ret = stream_socket_shutdown($ch,  STREAM_SHUT_RDWR);
                break;
        }*/

        if ($chState->listener !== null)
            $chState->listener->onClosed($ch);

        $this->removeChannelState($ch);

        $this->agent->selector->unregister($ch);

        try {
            $ret = fclose($ch);
            if($ret === false)
                BayLog::error("Cannot close socket: %s(%s)", $ch, SysUtil::lastErrorMessage());
        }
        catch(\Error $e) {
            BayLog::error_e($e, "Close failed (Ignore): %s", $ch);
        }
    }


    public function addChannelState($ch, ChannelState $chState) : void
    {
        BayLog::debug("%s add_channel_state ch=%s chState=%s", $this->agent, $ch, $chState);

        foreach($this->channelMap as $st) {
            if($st->channel == $ch)
                return;
        }
        $this->channelMap[] = $chState;
        $this->channelCount += 1;
    }

    private function removeChannelState($ch) : void
    {
        BayLog::trace("%s remove ch %s", $this->agent, $ch);
        for($i = 0; $i < count($this->channelMap); $i++) {
            if ($this->channelMap[$i]->channel == $ch) {
                ArrayUtil::removeByIndex($i, $this->channelMap);
                break;
            }
        }
        $this->channelCount--;
    }

    public function findChannelState($ch) : ?ChannelState
    {
        foreach($this->channelMap as $map) {
            if($map->channel == $ch)
                return $map;
        }
        return null;
    }


    private static function op_mode(int $op) : string
    {
        $op_str = "";
        if (($op & Selector::OP_READ) != 0)
            $op_str = "OP_READ";
        if (($op & Selector::OP_WRITE) != 0) {
            if ($op_str != "") {
                $op_str .= "|";
                $op_str .= "OP_WRITE";
            }
        }
        return $op_str;
    }
}

