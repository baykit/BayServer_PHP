<?php
namespace baykit\bayserver\docker;

use baykit\bayserver\docker\Docker;


interface Trouble extends Docker
{
    const GUIDE = 1;
    const TEXT = 2;
    const REROUTE = 3;
}