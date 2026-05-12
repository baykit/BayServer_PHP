<?php
namespace baykit\bayserver\docker\h3;

use baykit\bayserver\protocol\ProtocolException;

/**
 * Union of QicCommandHandler + onProtocolError. The actual H3
 * inbound handler implements both.
 */
interface QicHandler extends QicCommandHandler
{
    public function onProtocolError(ProtocolException $e) : bool;
}
