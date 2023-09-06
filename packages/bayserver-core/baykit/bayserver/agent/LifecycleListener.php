<?php
namespace baykit\bayserver\agent;

interface LifecycleListener
{
    public function add(int $agtId) : void;
    public function remove(int $agtId) : void;
}