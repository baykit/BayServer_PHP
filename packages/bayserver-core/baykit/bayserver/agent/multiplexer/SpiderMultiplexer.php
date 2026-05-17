<?php

namespace baykit\bayserver\agent\multiplexer;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\PortMap;
use baykit\bayserver\common\WriteUnit;
use baykit\bayserver\BayLog;
use baykit\bayserver\Sink;
use baykit\bayserver\rudder\StreamRudder;
use baykit\bayserver\rudder\UdpSocketRudder;
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

    /**
     * Queue of RudderStates whose writeQueue should be drained right
     * after the main select() dispatch, without going through an
     * OP_WRITE selector registration cycle.
     */
    private array $tryWriteList = [];

    /**
     * Selected keys carried over from a previous receive() because the
     * harbor.maxEventsPerReceive cap was hit. They are drained on the
     * next receive() before any new select() syscall.
     */
    private array $pendingSelKeys = [];

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
        return "SpiderMpx[". $this->agent. "]";
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

    public function reqWrite(Rudder $rd, string $buf, ?string $adr, $tag, bool $flush, ?callable $callback = null): bool
    {
        $st = $this->getRudderState($rd);
        BayLog::debug("%s reqWrite chState=%s tag=%s", $this->agent, $st, $tag);
        if($st == null || $st->closed) {
            BayLog::warn("%s Channel is closed: %s", $this->agent, $rd);
            // Closed: consumed listener fires with bufferAvailable=true
            // since there's no more queue to back up.
            $callback(true);
            return true;
        }

        $unt = new WriteUnit($buf, $adr, $tag, $callback);
        $st->writeQueue[] = $unt;
        // Maintain the O(1) pending-bytes counter. Pair
        // with the array_shift decrement in MultiplexerBase::consumeOldestUnit.
        $st->writeQueueBytes += strlen($buf);

        // Flush when the caller asked for it or the buffered data has
        // crossed the flush threshold. The threshold is min(bufSize,
        // shipBufferSize): bufSize coalesces headers + small bodies
        // into one syscall while shipBufferSize is the back-pressure
        // boundary set by harbor config. Without the min, when
        // shipBufferSize < bufSize the producer hits back-pressure
        // (bufferAvailable=false) before the drain trigger fires, and
        // both producer and consumer deadlock with the unit queued.
        $flushThreshold = min($st->bufSize, BayServer::$harbor->shipBufferSize());
        if ($st->remaining() > 0 && ($flush || $st->remaining() >= $flushThreshold)) {
            // Dedup: each onWritable() drains the rudder's full
            // writeQueue, so pushing the same state twice would replay
            // the loop and fire spurious zero-length WroteLetters
            // ("Write queue is empty" Sink in GrandAgent.onWrote). The
            // implementation uses a synchronized list and naturally enqueues
            // one entry per consumer; PHP is single-threaded per agent
            // so a flag on the state is enough.
            if (!$st->inTryWriteList) {
                $st->inTryWriteList = true;
                $this->tryWriteList[] = $st;
            }
            $this->wakeup();
        }

        $st->access();
        // Return the buffer-available signal so the caller can decide
        // whether to keep submitting writes.
        return $st->bufferAvailable();
    }

    public function reqTransfer(Rudder $rd, Rudder $fileRd, int $ofs, int $len, ?callable $callback): void {
        // PHP has no non-blocking sendfile equivalent
        // (stream_copy_to_stream is synchronous and would stall the
        // event loop). The framework wiring is preserved but no
        // working transfer path is reachable.
        throw new Sink();
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
        $this->agent->sendClosedLetter($st->rudder, $this, false);

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
        // GrandAgent::onWrote calls us when the WroteLetter for a
        // previous send leaves the writeQueue non-empty. Register
        // OP_WRITE so the next select() cycle reschedules onWritable
        // once the kernel send buffer has room. Without this the
        // rudder is in tryWriteList only until the first drain
        // attempt and never gets retried, freezing the connection
        // until something else happens to wakeup the agent.
        //
        // Only register when queue[0] actually has bytes left
        // (= partial write that needs retry). If queue[0] is already
        // drained (buf==""), its WroteLetter is in flight and will
        // pop it shortly; registering OP_WRITE in that case just
        // burns a spurious onWritable+cancelWrite roundtrip.

        if (empty($st->writeQueue)) {
            return;
        }
        $first = $st->writeQueue[0];
        if ($first->buf !== null && strlen($first->buf) === 0) {
            return;
        }
        $this->addOperation($st->rudder, Selector::OP_WRITE);
    }

    public final function closeRudder(RudderState $st) : void {
        $this->selector->unregister($st->rudder->key());
        parent::closeRudder($st);
    }

    public final function onBusy() : void {
        BayLog::debug("%s onBusy", $this->agent);
        // anchorablePortMap is a list of PortMap, not Rudder, so iterate
        // the values; the previous array_keys() pass gave int indices and
        // hit "Call to a member function key() on int" once the agent
        // crossed the maxInboundShips threshold (default 100, easy to hit
        //.
        foreach (BayServer::$anchorablePortMap as $portMap) {
            $this->selector->unregister($portMap->rudder->key());
        }
    }

    public final function onFree() : void {
        BayLog::debug("%s onFree aborted=%s", $this->agent, $this->agent->aborted);
        if ($this->agent->aborted) {
            return;
        }

        // Only the TCP-side multiplexer (= anchorable agent) issues
        // accept() on the listening TCP sockets. UDP-only agents see
        // datagrams via SpiderMultiplexer::onReadable on the UDP rudder
        // and never accept anything.
        if (!$this->anchorable) {
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

        // Per-receive event cap. When
        // harbor.maxEventsPerReceive is a positive value, drain at most
        // that many keys per call; leftover ready keys stay in
        // pendingSelKeys and are drained on subsequent receive() calls
        // without re-entering select(). Keeps the agent's hot working set
        // inside per-core L2 cache under high -c workloads. Default -1
        // disables the cap and restores the pre-tuning behaviour.
        $maxEvents = BayServer::$harbor->maxEventsPerReceive();
        $capped = $maxEvents > 0;
        $hasLeftover = $capped && !empty($this->pendingSelKeys);

        if ($hasLeftover) {
            // Still draining keys from a previous select(); skip the
            // syscall and keep the agent on the same connection subset
            // one more pass.
            $selectedMap = $this->pendingSelKeys;
            $this->pendingSelKeys = [];
        }
        else if (!$wait) {
            $selectedMap = $this->selector->select();
        }
        else {
            $selectedMap = $this->selector->select(GrandAgent::SELECT_TIMEOUT_SEC);
        }

        $this->registerChannelOps();

        if ($capped) {
            $processed = 0;
            foreach ($selectedMap as $i => $selKey) {
                if ($processed >= $maxEvents) {
                    // Stash the rest for the next receive() call. Keys
                    // remain valid because we never told the selector we
                    // were done with them.
                    $this->pendingSelKeys = array_slice($selectedMap, $i);
                    break;
                }
                if ($selKey->channel == $this->selectWakeupPipe[0]) {
                    $this->onWakedUp();
                }
                else {
                    $this->handleChannel($selKey);
                }
                $processed++;
            }
        }
        else {
            foreach($selectedMap as $selKey) {
                if ($selKey->channel == $this->selectWakeupPipe[0]) {
                    # Waked up by req_*
                    $this->onWakedUp();
                }
                else {
                    $this->handleChannel($selKey);
                }
            }
        }

        // Direct-write shortcut: instead of registering
        // OP_WRITE with the selector and waiting for the next select()
        // cycle, drain the queue right here by calling onWritable() on
        // each ready RudderState. Saves the addOperation + wakeup-pipe
        // write that reqWrite would otherwise have to do. If onWritable
        // can only partially flush, it will re-register OP_WRITE through
        // its existing partial-write path.
        while (!empty($this->tryWriteList)) {
            $st = array_shift($this->tryWriteList);
            $st->inTryWriteList = false;
            // The state may have been Returned to the pool between the
            // push (reqWrite) and now -- e.g. the peer closed the
            // connection and onClosed has already run. reset() clears
            // rudder/multiplexer/closed, so test for those before
            // touching the state. Skipping a stale entry is harmless;
            // any still-queued writes were already discarded by the
            // close path.
            if ($st->rudder === null || $st->multiplexer !== $this || $st->closed) {
                continue;
            }
            try {
                $this->onWritable($st);
            }
            catch (\Throwable $e) {
                BayLog::error_e($e, "%s Unhandled error on deferred write: rd=%s", $this->agent, $st->rudder);
                // Don't propagate -- a single bad rudder shouldn't
                // bring down the agent. onWritable's own catch already
                // sends an error letter for the well-formed cases.
            }
        }

        return !empty($selectedMap);
    }

    /**
     * Frozen 4-byte buffer reused for every wakeup write. The bytes
     * themselves are arbitrary -- onWakedUp drains the pipe and
     * discards the content -- so a single shared string avoids the
     * per-call pack("N", 0) allocation that IOUtil::writeInt32 would
     * otherwise do.
     */
    private const WAKEUP_BUF = "\x00\x00\x00\x00";

    public final function wakeup() : void {
        // Non-blocking fwrite: returns 4 normally, 0 or false when the
        // pipe is full. A full pipe means a wakeup byte is already
        // pending so dropping this write is correct -- the buffered
        // byte will wake select() once the consumer reaches it. The
        // @ suppresses PHP's "send of N bytes failed" notice on full
        // pipe; without the suppression-and-drop the producer thread
        // (= the same thread that drains the pipe in onWakedUp) would
        // see an error path and fatal_e the agent, dropping every
        // connection it was serving.
        // Symmetric with onWakedUp's direct fread.
        @fwrite($this->selectWakeupPipe[1], self::WAKEUP_BUF);
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
        // Selector gives us back a raw stream resource; resolve it to
        // the owning Rudder, then read $rd->state directly (post
        // after the refactor the state lives on the rudder, not in a per-mpx
        // hashmap).
        $rd = $this->findRudderByKey($ch);
        $st = $rd !== null ? $rd->state : null;
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
            $this->agent->sendErrorLetter($st->rudder, $this, $e, false);
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
            $this->agent->sendErrorLetter($st->rudder, $this, new IOException(SysUtil::lastErrorMessage()), false);
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

        $this->agent->sendAcceptedLetter($st->rudder, $this, $clientRd, false);
    }

    public function onConnectable(RudderState $st) : void
    {
        BayLog::debug("%s onConnectable (^o^)/: rd=%s", $this, $st->rudder);

        # check connection by sending 0 bytes data.
        $success = stream_socket_sendto($st->rudder->key(), "");
        BayLog::debug("%s write zero bytes success=%s", $this, $success);
        if ($success === false || $success == -1) {
            BayLog::error("Connect failed: %s", SysUtil::lastErrorMessage());
            $this->agent->sendErrorLetter($st->rudder, $this, new IOException("Connect failed: ". SysUtil::lastErrorMessage()), false);
            return;
        }
        $this->agent->sendConnectedLetter($st->rudder, $this, false);
    }

    public function onReadable(RudderState $st) : void
    {
        BayLog::debug("%s onReadable: %s", $this, $st);

        try {
            if ($st->rudder instanceof UdpSocketRudder) {
                // UDP: each datagram has its own source address.
                // stream_socket_recvfrom returns the bytes and fills
                // $peer with "host:port" (or "host" for unix-domain).
                // Pass the address along on the ReadLetter so the
                // QicTransporter can route it. onRead's UdpSocketRudder
                // branch.
                $peer = "";
                $buf = stream_socket_recvfrom($st->rudder->key(), 65536, 0, $peer);
                if ($buf === false) {
                    throw new IOException("recvfrom failed: ". SysUtil::lastErrorMessage());
                }
                $st->readBuf = $buf;
                $this->agent->sendReadLetter($st->rudder, $this, strlen($buf), $peer, false);
                return;
            }

            $st->readBuf = $st->rudder->read($st->bufSize);

            $this->agent->sendReadLetter($st->rudder, $this, strlen($st->readBuf), null, false);
        }
        catch(\Exception $e) {
            BayLog::debug_e($e, "%s Unhandled error", $this);
            $this->agent->sendErrorLetter($st->rudder, $this, $e, false);
        }
    }

    public function onWritable(RudderState $st) : void
    {
        try {
            if (empty($st->writeQueue)) {
                BayLog::debug("%s No data to write: rd=%s", $this, $st->rudder);
                $this->cancelWrite($st);
                return;
            }

            $isUdp = $st->rudder instanceof UdpSocketRudder;

            for($i = 0; $i < count($st->writeQueue); $i++) {
                $wunit = $st->writeQueue[$i];

                // Skip units already drained by a previous onWritable
                // whose WroteLetter is still in flight. Without this,
                // a second onWritable on the same queue snapshot (e.g.
                // an OP_WRITE selector event firing on top of a recent
                // tryWriteList drain) would emit duplicate
                // zero-length WroteLetters, eventually outrunning the
                // queue depth and triggering "Write queue is empty"
                // in GrandAgent::onWrote. Originally-empty units
                // (initialSize == 0, used as terminators) are still
                // processed via the strlen==0 branch below.
                if ($wunit->initialSize > 0 && strlen($wunit->buf) === 0) {
                    continue;
                }

                BayLog::debug("%s Try to write: pkt=%s buflen=%d rd=%s", $this, $wunit->tag,
                    strlen($wunit->buf), $st->rudder);

                $bufsize = strlen($wunit->buf);
                if (strlen($wunit->buf) == 0) {
                    $len = 0;
                }
                else if ($isUdp) {
                    // UDP: each WriteUnit carries its own destination
                    // address. sendto is atomic per datagram -- never
                    // returns a short write; either the whole datagram
                    // goes out or it errors. send path.
                    $len = @stream_socket_sendto($st->rudder->key(), $wunit->buf, 0, $wunit->adr);
                    if ($len === false || $len < 0) {
                        throw new IOException("sendto failed: ". SysUtil::lastErrorMessage());
                    }
                    BayLog::debug("%s wrote %d bytes (udp to %s)", $this, $len, $wunit->adr);
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
                            throw new IOException($this. " Too many retry count to write");
                        }
                        # Data remains
                        break;
                    }
                    else {
                        $st->writeTryCount = 0;
                        $wunit->buf = substr($wunit->buf, $len);
                    }
                }
                $this->agent->sendWroteLetter($st->rudder, $this, $len, false);

                if($len < $bufsize) {
                    BayLog::debug("%s Data remains", $this);
                    break;
                }
            }
        }
        catch(IOException $e) {
            BayLog::debug_e($e, "%s IO error", $this);
            $this->agent->sendErrorLetter($st->rudder, $this, $e, false);
        }
    }

    public function onWakedUp() : void
    {
        BayLog::trace("%s On Waked Up", $this);
        // Drain in 4096-byte chunks instead of 4-byte recvInt32 calls.
        // Under high concurrency and multi-chunk responses, wakeup() fires
        // several times per request and the pipe accumulates thousands
        // of 4-byte wakeup tokens; recvInt32 would burn a full syscall
        // per token. fread on a non-blocking stream returns "" when
        // the pipe is empty, so the loop terminates after one extra
        // empty read.
        while (true) {
            $ret = @fread($this->selectWakeupPipe[0], 4096);
            if ($ret === false || $ret === "") {
                break;
            }
        }
    }

    private static function op_mode(int $op) : string
    {
        $op_str = "";
        if (($op & Selector::OP_READ) != 0)
            $op_str = "OP_READ";
        if (($op & Selector::OP_WRITE) != 0) {
            if ($op_str != "") {
                $op_str.= "|";
            }
            $op_str.= "OP_WRITE";
        }
        return $op_str;
    }
}









