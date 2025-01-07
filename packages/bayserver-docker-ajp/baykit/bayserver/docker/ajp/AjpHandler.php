<?php

namespace baykit\bayserver\docker\ajp;

use baykit\bayserver\protocol\ProtocolException;

interface AjpHandler extends AjpCommandHandler {

    function onProtocolError(ProtocolException $e): bool;
}