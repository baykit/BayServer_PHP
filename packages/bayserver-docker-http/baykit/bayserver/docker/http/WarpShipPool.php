<?php
namespace baykit\bayserver\docker\http;

use baykit\bayserver\common\WarpShip;

/**
 * Per-agent pool of active WarpShips that support H2-style stream
 * multiplex. Unlike WarpShipStore (which manages physical WarpShip
 * objects and is unaware of tours), this pool reasons about how
 * many tours each ship is currently carrying, so that an arriving
 * tour can be routed to the least-loaded ship with capacity.
 *
 * Lifecycle: HtpWarpDocker adds a ship to the pool when arrive()
 * rents a fresh one (= onShipRented), and removes it when the
 * connection closes (= onEndShip). Tour attach/detach is handled
 * on the ship itself via its tourMap; this pool only routes.
 * Mirrors WarpShipPool.
 */
class WarpShipPool
{
    /** @var WarpShip[] */
    private array $ships = [];

    /**
     * Find the WarpShip currently carrying the fewest tours that
     * still has room for one more. Returns null when no eligible
     * ship exists -- caller falls back to opening a fresh
     * connection.
     */
    public function findIdlest() : ?WarpShip
    {
        $best = null;
        $bestCount = PHP_INT_MAX;
        foreach ($this->ships as $ws) {
            // Accept ships whose connect has not completed yet:
            // tours attached before notifyConnect buffer in
            // WarpShip.cmdBuf and flush together once the socket
            // is up. Folding parallel arrivals onto the in-flight
            // ship avoids start-up thundering-herd where every
            // arrival opens its own backend connection because no
            // prior ship is "ready".
            if ($ws->protocolHandler === null) continue;
            $cap = $ws->warpHandler()->maxMultiplexedTours();
            $n = $ws->tourCount();
            if ($n < $cap && $n < $bestCount) {
                $best = $ws;
                $bestCount = $n;
                if ($n === 0) break; // can't be more idle
            }
        }
        return $best;
    }

    public function add(WarpShip $ws) : void
    {
        $this->ships[] = $ws;
    }

    public function remove(WarpShip $ws) : void
    {
        foreach ($this->ships as $i => $existing) {
            if ($existing === $ws) {
                array_splice($this->ships, $i, 1);
                return;
            }
        }
    }

    public function size() : int
    {
        return count($this->ships);
    }
}
