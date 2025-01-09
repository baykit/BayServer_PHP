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
    protected array $rudders = [];

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
        $key = $rd->key();

        $this->rudders[$this->getKeyId($key)] = $st;
        $this->rudderCount++;

        $st->access();
    }

    public function removeRudderState(Rudder $rd): void
    {
        unset($this->rudders[$this->getKeyId($rd->key())]);
        $this->rudderCount--;
    }


    public final function getRudderState(Rudder $rd): ?RudderState {
        return $this->findRudderStateByKey($rd->key());
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
        $u->done();
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

    protected final function findRudderStateByKey($key) : ?RudderState
    {
        $keyId = $this->getKeyId($key);
        if(array_key_exists($keyId, $this->rudders))
            return $this->rudders[$keyId];
        else
            return null;
    }

    protected final function closeTimeoutSockets() : void {
        if(empty($this->rudders))
            return;

        $closeList = [];
        $copied = $this->rudders;

        $now = time();

        foreach ($copied as $st) {
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
        foreach ($copied as $st) {
            if($st->rudder != $this->agent->commandReceiver->rudder)
                $this->closeRudder($st->rudder);
        }
    }
}









