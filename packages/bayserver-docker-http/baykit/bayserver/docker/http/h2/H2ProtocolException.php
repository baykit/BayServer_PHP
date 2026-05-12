<?php

namespace baykit\bayserver\docker\http\h2;

use baykit\bayserver\protocol\ProtocolException;

/**
 * A ProtocolException that carries the specific HTTP/2 error code
 * that should appear in the resulting GOAWAY frame. The base class
 * always maps to H2ErrorCode::PROTOCOL_ERROR; this subclass lets
 * call sites pick FLOW_CONTROL_ERROR, COMPRESSION_ERROR, etc.
 * Mirrors
 */
class H2ProtocolException extends ProtocolException
{
    public int $errorCode;

    public function __construct(int $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }
}
