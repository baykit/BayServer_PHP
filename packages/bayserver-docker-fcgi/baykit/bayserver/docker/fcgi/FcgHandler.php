<?php

namespace baykit\bayserver\docker\fcgi;

use baykit\bayserver\protocol\ProtocolException;

interface FcgHandler extends FcgCommandHandler {

    function onProtocolError(ProtocolException $e): bool;
}