<?php

namespace baykit\bayserver\docker\http\h1;

use baykit\bayserver\protocol\ProtocolException;

interface H1Handler extends H1CommandHandler {

    function onProtocolError(ProtocolException $e): bool;
}