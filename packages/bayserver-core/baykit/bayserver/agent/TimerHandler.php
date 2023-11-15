<?php
namespace baykit\bayserver\agent;

interface TimerHandler
{
    public function onTimer() : void;
}