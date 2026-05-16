<?php
namespace baykit\bayserver\docker\h3\command;

/**
 * H3 DATA event marker. The actual body bytes are pulled with
 * `H3 Connection::recvBody($stmId, $buf)` inside the inbound handler.
 */
class CmdData
{
    public int $stmId;

    public function __construct(int $stmId)
    {
        $this->stmId = $stmId;
    }
}
