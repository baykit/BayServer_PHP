<?php

namespace baykit\bayserver\docker\http\h2;

use baykit\bayserver\protocol\ProtocolException;

interface H2Handler extends H2CommandHandler {

    function onProtocolError(ProtocolException $e): bool;
}