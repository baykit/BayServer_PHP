<?php

namespace baykit\bayserver\docker\phpverse;

use baykit\bayserver\agent\LifecycleListener;

/**
 * Per-agent lifecycle listener for PhpVerseDocker.
 *
 * Recipe β: the very first agent to start triggers a one-shot spawn
 * of the global phpverse daemon. Subsequent add() calls (= more
 * agents, more PhpVerseDocker instances pointing at the same phpverse)
 * short-circuit on the docker's static $phpverseSpawned flag.
 *
 * remove() is a no-op: phpverse keeps running until the BayServer
 * process exits. The shutdown function registered by
 * PhpVerseDocker::spawnPhpverse() is the kill point.
 *
 * The standard per-agent WarpShipStore is created by WarpBase's own
 * listener (added independently by parent::init()), not by us.
 */
class PhpVerseDocker_AgentListener implements LifecycleListener
{
    private PhpVerseDocker $docker;

    public function __construct(PhpVerseDocker $docker)
    {
        $this->docker = $docker;
    }

    public function add(int $agtId): void
    {
        $this->docker->ensureSpawned();
    }

    public function remove(int $agtId): void
    {
        // No-op. Daemon lives until BayServer process exit (shutdown hook).
    }
}
