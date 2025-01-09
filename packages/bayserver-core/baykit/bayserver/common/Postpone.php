<?php

namespace baykit\bayserver\common;

interface Postpone
{
    public function run(): void;
}