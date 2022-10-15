<?php
namespace baykit\bayserver\docker;

use baykit\bayserver\docker\Docker;

interface Harbor extends Docker
{
    const FILE_SEND_METHOD_SELECT = 1;
    const FILE_SEND_METHOD_SPIN = 2;
    const FILE_SEND_METHOD_TAXI = 3;
}