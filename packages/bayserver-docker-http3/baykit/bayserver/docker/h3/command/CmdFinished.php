<?php
namespace baykit\bayserver\docker\h3\command;

/**
 * H3 FINISHED event marker. Signals that the peer is done sending on
 * the stream (= request body fully received). CmdFinished.
 */
class CmdFinished
{
    public int $stmId;

    public function __construct(int $stmId)
    {
        $this->stmId = $stmId;
    }
}
