<?php

namespace baykit\bayserver\common;


/**
 * Letter receiver
 */
interface Recipient
{
    /**
     * Receives letters.
     * @param wait blocking mode
     */
    public function receive(bool $wait): bool;

    /**
     * Wakes up the recipient
     */
    public function wakeup(): void;
}