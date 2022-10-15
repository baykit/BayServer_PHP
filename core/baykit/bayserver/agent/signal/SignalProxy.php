<?php

namespace baykit\bayserver\agent\signal;

class SignalProxy
{
    public static function register(int $sig, callable $handler)
    {
        pcntl_signal($sig, $handler);
    }
}