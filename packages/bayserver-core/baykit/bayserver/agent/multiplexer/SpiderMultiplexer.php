<?php

namespace baykit\bayserver\agent\multiplexer;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\PortMap;
use baykit\bayserver\BayLog;
use baykit\bayserver\rudder\StreamRudder;
use baykit\bayserver\BayServer;
use baykit\bayserver\agent\TimerHandler;
use \baykit\bayserver\common\RudderState;
use baykit\bayserver\common\Recipient;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\util\BlockingIOException;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\Selector;
use baykit\bayserver\util\Selector_Key;
use baykit\bayserver\util\IOUtil;
use baykit\bayserver\util\SysUtil;
use baykit\bayserver\util\StringUtil;

class ChannelOperation {
    public Rudder $rudder;
    public int $op;
    public bool $toConnect;

    public function __construct(Rudder $rd, int $op, bool $toConnect) {
        $this->rudder = $rd;
        $this->op = $op;
        $this->toConnect = $toConnect;
    }
}


/**
 * The purpose of SpiderMultiplexer is to monitor sockets, pipes, or files through the select/epoll/kqueue API.
 */
class SpiderMultiplexer extends MultiplexerBase implements TimerHandler, Recipient {

    private bool $anchorable;
    private Selector $selector;
    private array $operations = [];
    public $selectWakeupPipe = [];

    public function __construct(GrandAgent $agt, bool $anchorable)
    {
        parent::__construct($agt);
        $this->anchorable = $anchorable;
        $this->selector = new Selector();

        $this->selectWakeupPipe = IOUtil::openLocalPipe();
        stream_set_blocking($this->selectWakeupPipe[0], false);
        stream_set_blocking($this->selectWakeupPipe[1], false);
        BayLog::debug("%s Register pipe: %s", $agt, $this->selectWakeupPipe[0]);
        $this->selector->register($this->selectWakeupPipe[0], Selector::OP_READ);

        $this->agent->addTimerHandler($this);
    }

    public function __toString(): string {
        return "SpiderMpx[" . $this->agent . "]";
    }

    ////////////////////////////////////////////
    // Implements Multiplexer
    ////////////////////////////////////////////
    public final function reqAccept(Rudder $rd): void
    {
        $st = $this->getRudderState($rd);
        BayLog::debug("%s reqAccept rd=%s", $this, $rd);

        $this->selector->register($rd->key(), Selector::OP_READ);
        $st->accepting = true;
    }

    public final function reqConnect(Rudder $rd, string $addr): void
    {
        $st = $this->getRudderState($rd);
        BayLog::debug("%s reqConnect addr=%s rd=%s", $this, $addr, $rd);

        $this->addOperation($rd, Selector::OP_WRITE, false, true);
    }

    public final function reqRead(Rudder $rd): void
    {
        $st = $this->getRudderState($rd);
        BayLog::debug("%s reqRead rd=%s chState=%s", $this->agent, $rd, $st);

        if($st == null) {
            BayLog::warn("%s Unknown rudder rd=%s", $this->agent, $rd);
            return;
        }

        $this->addOperation($rd, Selector::OP_READ);
        $st->access();
    }

    public function reqWrite(Rudder $rd, string $buf, ?string $adr, $tag, ?callable $callback = null): void
    {
        $st = $this->getRudderState($rd);
        BayLog::debug("%s reqWrite chState=%s tag=%s", $this->agent, $st, $tag);
        if($st == null || $st->closed) {
            BayLog::warn("%s Channel is closed: %s", $this->agent, $rd);
            $callback();
            return;
        }

        $unt = new WriteUnit($buf, $adr, $tag, $callback);
        $st->writeQueue[] = $unt;
        $this->addOperation($rd, Selector::OP_WRITE);

        $st->access();
    }

    public function reqEnd(Rudder $rd): void {
        $st = $this->getRudderState($rd);
        if($st == null) {
            return;
        }

        $st->end();
        $st->access();
    }

    public function reqClose(Rudder $rd): void {
        $st = $this->getRudderState($rd);
        BayLog::debug("%s reqClose chState=%s", $this->agent, $st);

        if($st == null || $st->closed) {
            BayLog::debug("%s rudder not found or closed: st=%s", $this->agent, $st);
            return;
        }

        $this->closeRudder($st);
        $this->agent->sendClosedLetter($st, false);

        $st->access();
    }

    public function shutdown(): void {
        $this->wakeup();
    }

    public final function isNonBlocking() : bool {
        return true;
    }

    public final function useAsyncAPI() : bool {
        return true;
    }

    public final function cancelRead(RudderState $st) : void {
        $this->selector->unregister($st->rudder->key());
    }

    public final function cancelWrite(RudderState $st) : void
    {
        $op = $this->selector->getOp($st->rudder->key()) & ~Selector::OP_WRITE;
        # Write OP off
        if ($op != Selector::OP_READ) {
            $this->selector->unregister($st->rudder->key());
        }
        else {
            $this->selector->modify($st->rudder->key(), $op);
        }
    }

    public final function nextAccept(RudderState $st) : void {

    }

    public final function nextRead(RudderState $st) : void {

    }

    public final function nextWrite(RudderState $st) : void {

    }

    public final function closeRudder(RudderState $st) : void {
        $this->selector->unregister($st->rudder->key());
        parent::closeRudder($st);
    }

    public final function onBusy() : void {
        BayLog::debug("%s onBusy", $this->agent);
        foreach(array_keys(BayServer::$anchorablePortMap) as $rd ) {
            $this->selector->unregister($rd->key());
            $st = $this->getRudderState($rd);
        }
    }

    public final function onFree() : void {
        BayLog::debug("%s onFree aborted=%s", $this->agent, $this->agent->aborted);
        if ($this->agent->aborted) {
            return;
        }

        foreach(BayServer::$anchorablePortMap as $portMap) {
            BayLog::debug("%s reqAccept rd=%s", $this->agent, $portMap->rudder);
            $this->reqAccept($portMap->rudder);
        }
    }

    ////////////////////////////////////////////
    // Implements TimerHandler
    ////////////////////////////////////////////

    public final function onTimer() : void {
        $this->closeTimeoutSockets();
    }

    ////////////////////////////////////////////
    // Implements Recipient
    ////////////////////////////////////////////

    public final function receive(bool $wait) : bool {

        if (!$wait) {
            $selectedMap = $this->selector->select();
        }
        else {
            $selectedMap = $this->selector->select(GrandAgent::SELECT_TIMEOUT_SEC);
        }

        $this->registerChannelOps();

        foreach($selectedMap as $selKey) {
            if ($selKey->channel == $this->selectWakeupPipe[0]) {
                # Waked up by req_*
                $this->onWakedUp();
            }
            else {
                $this->handleChannel($selKey);
            }
        }

        return !empty($selectedMap);
    }

    public final function wakeup() : void {
        IOUtil::writeInt32($this->selectWakeupPipe[1], 0);
    }

    ////////////////////////////////////////////
    // Private methods
    ////////////////////////////////////////////

    public function addOperation(Rudder $rd, $op, $close=false, $connect=false) : void
    {
        $found = false;
        foreach ($this->operations as $rd_op) {
            if ($rd_op->rudder == $rd) {
                $rd_op->op |= $op;
                $rd_op->toConnect = $rd_op->toConnect || $connect;
                $found = true;
                BayLog::debug("%s Update operation: op=%d(%s) rd=%s", $this, $rd_op->op, SpiderMultiplexer::op_mode($rd_op->op), $rd_op->rudder);
            }
        }

        if (!$found) {
            BayLog::debug("%s New operation: %d(%s) rd=%s", $this, $op, SpiderMultiplexer::op_mode($op), $rd);
            $this->operations[] = new ChannelOperation($rd, $op, $connect);
        }
        BayLog::trace("%s wakeup", $this->agent);
        $this->wakeup();
    }

    public function registerChannelOps(): int
    {
        if (count($this->operations) == 0)
            return 0;

        $nch = count($this->operations);
        foreach ($this->operations as $rdOp) {
            $st = $this->getRudderState($rdOp->rudder);

            if (!is_resource($rdOp->rudder->key())) {
                BayLog::debug("Rudder already closed: rd=%s", $rdOp->rudder->key());
                continue;
            }

            if($st == null && $rdOp->close) {
                // already closed
                BayLog::debug("%s cannot register rudder: (rudder is closed)");
                continue;
            }

            try {
                $ch = $rdOp->rudder->key();

                BayLog::debug("%s register op=%s chState=%s", $this, self::op_mode($rdOp->op), $st);
                $op = $this->selector->getOp($ch);
                if ($op === null) {
                    $this->selector->register($ch, $rdOp->op);
                } else {
                    $newOp = $op | $rdOp->op;
                    BayLog::debug("Already registered op=%s update to %s", self::op_mode($op), self::op_mode($newOp));
                    $this->selector->modify($ch, $newOp);
                }

                if ($rdOp->toConnect) {
                    if ($st === null)
                        BayLog::warn("%s register connect but ChannelState is null: %s", $this, $ch);
                    else
                        $st->connecting = true;
                }

            } catch (\Exception $e) {
                $cst = $this->findChannelState($rdOp->ch);
                BayLog::error_e($e, "%s Cannot register operation: %s", $this, ($cst !== null) ? $cst->listener : null);
            }
        }

        $this->operations = [];
        return $nch;
    }


    public function handleChannel(Selector_Key $key)
    {
        $ch = $key->channel;
        $st = $this->findRudderStateByKey($ch);
        if ($st === null) {
            BayLog::error("%s Channel state is not registered: ch=%s op=%s", $this, $ch, $key->operation);
            $this->selector->unregister($ch);
            return;
        }

        BayLog::debug("%s chState=%s Handle channel: operation=%d connecting=%s closing=%s",
                        $this->agent, $st, $key->operation,
                        $st->connecting, $st->closing);

        $nextAction = null;
        try {

            if ($st->connecting) {
                $st->connecting = false;
                # connectable
                $this->onConnectable($st);

                // Handle as "Write Off"
                $op = $this->selector->getOp($ch);
                $op = $op & ~Selector::OP_WRITE;
                if ($op != Selector::OP_READ)
                    $this->selector->unregister($ch);
                else
                    $this->selector->modify($ch, $op);
            }
            elseif ($st->accepting) {
                $this->onAcceptable($st);
            }
            else {
                if ($key->readable()) {
                    $this->onReadable($st);
                }
                if ($key->writable()) {
                    $this->onWritable($st);
                }
            }
        }
        catch(\Exception $e) {
            BayLog::info("%s Unhandled error error: %s (skt=%s)", $this, $e, $ch);
            $this->agent->sendErrorLetter($st, $e, false);
        }

        $st->access();
    }

    public function onAcceptable(RudderState $st) : void
    {
        $rd = $st->rudder;
        BayLog::debug("%s on_acceptable", $this->agent);

        $portDkr = PortMap::findDocker($rd, BayServer::$anchorablePortMap);
        BayLog::debug("%s Port docker secure=%s", $this->agent, $portDkr->secure() ? "t" : "f");

        // Specifies timeout because in some cases accept() don't seem to work in no blocking mode.
        $timeoutSec = $portDkr->nonBlockingTimeoutMillis / 1000.0;
        //BayLog::debug("%s timeoutSec=%f", $this->agent, $timeoutSec);

        if (($clientSkt = stream_socket_accept($rd->key(), $timeoutSec)) === false) {

            //error_reporting($level);
            // Timeout or another agent get client socket
            if ($portDkr->secure()) {
                while ($msg = openssl_error_string())
                    BayLog::error("%s SSL Error: %s", $this->agent, $msg);
                #BayLog::debug("%s Cert error or plain text", $this->agent);
            }
            $msg = SysUtil::lastErrorMessage();
            BayLog::debug("%s [port=%d] Error: %s", $this->agent, $portDkr->port(), SysUtil::lastErrorMessage());
            if(StringUtil::contains($msg, "timed out") || StringUtil::contains($msg, "Success")) {
                // time out is OK
                return;
            }
            $this->agent->sendErrorLetter($st, new IOException(SysUtil::lastErrorMessage()), false);
            return;
        }
        //error_reporting($level);

        BayLog::debug("%s Accepted: skt=%s", $this->agent, $clientSkt);
        $params = stream_context_get_params($clientSkt);
        $opts = stream_context_get_options($clientSkt);

        stream_set_blocking($clientSkt, false);
        if ($portDkr->secure()) {
            // SSL stream socket does not work as nonblocking.
            BayLog::debug("Set timeout");
            stream_set_blocking($clientSkt, true);
            stream_set_timeout($clientSkt, 0, $portDkr->nonBlockingTimeoutMillis * 1000);
        }

        $clientRd = new StreamRudder($clientSkt);
        BayLog::debug("%s Accepted: rd=%s", $this->agent, $clientRd);

        $this->agent->sendAcceptedLetter($st, $clientRd, false);
    }

    public function onConnectable(RudderState $st) : void
    {
        BayLog::debug("%s onConnectable (^o^)/: rd=%s", $this, $st->rudder);

        # check connection by sending 0 bytes data.
        $success = stream_socket_sendto($st->rudder->key(), "");
        BayLog::debug("%s write zero bytes success=%s", $this, $success);
        if ($success === false || $success == -1) {
            BayLog::error("Connect failed: %s", SysUtil::lastErrorMessage());
            $this->agent->sendErrorLetter($st, new IOException("Connect failed: " . SysUtil::lastErrorMessage()), false);
            return;
        }
        $this->agent->sendConnectedLetter($st, false);
    }

    public function onReadable(RudderState $st) : void
    {
        BayLog::debug("%s onReadable: %s", $this, $st);

        try {
            $st->readBuf = $st->rudder->read($st->bufSize);

            $this->agent->sendReadLetter($st, strlen($st->readBuf), null, false);
        }
        catch(\Exception $e) {
            BayLog::debug_e($e, "%s Unhandled error", $this);
            $this->agent->sendErrorLetter($st, $e, false);
        }
    }

    public function onWritable(RudderState $st) : void
    {
        try {
            if (empty($st->writeQueue)) {
                throw new IOException("%s No data to write: rd=%s", $this->agent, $st->rudder);
            }

            for($i = 0; $i < count($st->writeQueue); $i++) {
                $wunit = $st->writeQueue[$i];

                BayLog::debug("%s Try to write: pkt=%s buflen=%d rd=%s", $this, $wunit->tag,
                    strlen($wunit->buf), $st->rudder);

                $bufsize = strlen($wunit->buf);
                if (strlen($wunit->buf) == 0) {
                    $len = 0;
                }
                else {
                    $len = $st->rudder->write($wunit->buf);

                    BayLog::debug("%s wrote %d bytes", $this, $len);
                    if($len == 0) {
                        if (!is_resource($st->rudder->key()) && socket_last_error($st->rudder->key())) {
                            $msg = socket_strerror(socket_last_error($st->rudder->key()));
                            socket_clear_error($st->rudder->key());
                            throw new IOException($msg);
                        }

                        $st->writeTryCount++;
                        if ($st->writeTryCount > 100) {
                            throw new IOException($this . " Too many retry count to write");
                        }
                        # Data remains
                        break;
                    }
                    else {
                        $st->writeTryCount = 0;
                        $wunit->buf = substr($wunit->buf, $len);
                    }
                }
                $this->agent->sendWroteLetter($st, $len, false);

                if($len < $bufsize) {
                    BayLog::debug("%s Data remains", $this);
                    break;
                }
            }
        }
        catch(IOException $e) {
            BayLog::debug_e($e, "%s IO error", $this);
            $this->agent->sendErrorLetter($st, $e, false);
        }
    }

    public function onWakedUp() : void
    {
        BayLog::trace("%s On Waked Up", $this);
        try {
            while (true) {
                IOUtil::recvInt32($this->selectWakeupPipe[0]);
            }
        }
        catch(BlockingIOException $e) {
            /* Data not received */
        }
    }

    private static function op_mode(int $op) : string
    {
        $op_str = "";
        if (($op & Selector::OP_READ) != 0)
            $op_str = "OP_READ";
        if (($op & Selector::OP_WRITE) != 0) {
            if ($op_str != "") {
                $op_str .= "|";
            }
            $op_str .= "OP_WRITE";
        }
        return $op_str;
    }
}









