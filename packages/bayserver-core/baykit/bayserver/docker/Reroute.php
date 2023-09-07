<?php
namespace baykit\bayserver\docker;

interface Reroute extends Docker
{
    public function reroute(Town $twn, string $uri) : string;
}