<?php

namespace baykit\bayserver\agent\multiplexer;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\BayLog;
use baykit\bayserver\common\Multiplexer;
use baykit\bayserver\common\RudderState;
use baykit\bayserver\common\Transporter;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\util\IOException;

abstract class MultiplexerBase implements Multiplexer {

    private int $rudderCount = 0;
    protected ?GrandAgent $agent = null;
    // Iteration-only list of Rudder objects (uses
    // ArrayList<Rudder>). Lookup goes through $rudder->state, not this
    // array; we keep it solely for closeTimeoutSockets / closeAll.
    protected array $rudders = [];
    // Index: stream-resource id -> Rudder. Needed because the PHP
    // Selector callback gives us back the raw stream resource ; this
    // index lets us go from $key->channel back to its owning Rudder
    // without a linear scan of $this->rudders.
    protected array $rudderByKeyId = [];

    public function __construct(GrandAgent $agt)
    {
        $this->agent = $agt;
    }



    ////////////////////////////////////////////
    // Implements Multiplexer
    ////////////////////////////////////////////
    public final function addRudderState(Rudder $rd, RudderState $st): void
    {
        BayLog::trace("%s add rd=%s chState=%s", $this->agent, $rd, $st);
        $st->multiplexer = $this;
        // Per-rudder state slot: the rudder itself carries
        // its RudderState, eliminating the hashmap keyed by resource id.
        $rd->state = $st;
        $this->rudders[] = $rd;
        // Side-index for the selector callback (see $rudderByKeyId
        // comment in the field declaration).
        $this->rudderByKeyId[$this->getKeyId($rd->key())] = $rd;
        $this->rudderCount++;

        $st->access();
    }

    public function removeRudderState(Rudder $rd): void
    {
        $rd->state = null;
        // Drop from the iteration list (linear search, fine for the
        // scale of rudders we hold). remove(rd) call path.
        foreach ($this->rudders as $i => $r) {
            if ($r === $rd) {
                array_splice($this->rudders, $i, 1);
                break;
            }
        }
        unset($this->rudderByKeyId[$this->getKeyId($rd->key())]);
        $this->rudderCount--;
    }


    public final function getRudderState(Rudder $rd): ?RudderState {
        // Direct field read replaces the resource-id-keyed hashmap lookup
        //.
        return $rd->state;
    }

    public final function getTransporter(Rudder $rd): Transporter {
        return $this->getRudderState($rd)->transporter;
    }

    public function reqEnd(Rudder $rd): void {
        throw new Sink();
    }

    public function reqClose(Rudder $rd): void {
        throw new Sink();
    }

    public function consumeOldestUnit(RudderState $st): bool {
        if(empty($st->writeQueue))
            return false;

        $u = array_shift($st->writeQueue);
        // O(1) pending-bytes counter decrement to match the enqueue
        // increment in {Spider,Spin}Multiplexer::reqWrite.
        // Use the unit's initialSize captured at construction: by the
        // time we pop, SpiderMultiplexer::onWritable has shrunk $u->buf
        // in place down to "" (substr after each successful write), so
        // strlen($u->buf) would always return 0 and the counter would
        // grow unboundedly. upstream's comment notes that the counter
        // slightly overestimates during partial writes; that is fine
        // for the back-pressure threshold check.
        $st->writeQueueBytes -= $u->initialSize;
        if ($st->writeQueueBytes < 0)
            $st->writeQueueBytes = 0;
        // Pass the post-consumption bufferAvailable flag.
        // After we've popped the unit, the actual buffer state may have
        // freed up; we report it here so the upstream consume listener
        // can resume the producer.
        $u->done($st->bufferAvailable());
        return true;
    }

    public function closeRudder(RudderState $st): void {
        BayLog::debug("%s closeRd %s state=%s closed=%b", $this->agent, $st->rudder, $st, $st->closed);

        try {
            BayLog::trace("%s OS Close", $this->agent);
            $st->rudder->close();
        }
        catch(IOException $e) {
            BayLog::error_e($e);
        }
    }

    public final function isBusy() : bool {
        return $this->rudderCount >= $this->agent->maxInboundShips;
    }

    ////////////////////////////////////////////
    // Custom methods
    ////////////////////////////////////////////

    protected final function getKeyId($key) : int
    {
        if(is_object($key))
            return spl_object_id($key);
        else
            return get_resource_id($key);
    }

    /**
     * Reverse-lookup: given the raw stream resource the Selector hands
     * back, find the owning Rudder so the caller can read $rd->state.
     * Replaces the old findRudderStateByKey() which returned the state
     * directly via a key-id hashmap.
     */
    protected final function findRudderByKey($key) : ?Rudder
    {
        $keyId = $this->getKeyId($key);
        return $this->rudderByKeyId[$keyId] ?? null;
    }

    protected final function closeTimeoutSockets() : void {
        if(empty($this->rudders))
            return;

        $closeList = [];
        $copied = $this->rudders;

        $now = time();

        foreach ($copied as $rd) {
            $st = $rd->state;
            if ($st === null)
                continue;
            if($st->transporter != null) {
                if ($st->transporter->checkTimeout($st->rudder, $now - $st->lastAccessTime)) {
                    BayLog::debug("%s timeout: rd=%s", $this->agent, $st->rudder);
                    $closeList[] = $st;
                }
            }
        }

        foreach ($closeList as $c) {
            $this->reqClose($c->rudder);
        }
    }

    protected final function closeAll(): void {
        // Use copied ArrayList to avoid ConcurrentModificationException
        $copied = $this->rudders;
        foreach ($copied as $rd) {
            $st = $rd->state;
            if ($st === null)
                continue;
            if($st->rudder != $this->agent->commandReceiver->rudder)
                $this->closeRudder($st->rudder);
        }
    }
}









