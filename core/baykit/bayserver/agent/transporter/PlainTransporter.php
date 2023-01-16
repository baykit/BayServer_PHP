<?php

namespace baykit\bayserver\agent\transporter;

use baykit\bayserver\BayLog;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\SysUtil;

class PlainTransporter extends Transporter
{
    public function __construct(bool $serverMode, int $bufsize, bool $wtOnly = false)
    {
        parent::__construct($serverMode, $bufsize, false, $wtOnly);
    }

    public function init($nbHnd, $ch, $lis) : void
    {
        parent::init($nbHnd, $ch, $lis);
        $this->handshaked = true;  # plain socket doesn't need to handshake
    }

    public function __toString() : string
    {
        return "tp[{$this->dataListener}]";
    }

    /////////////////////////////////////////////////////////////////////////////////
    // implements Transporter
    /////////////////////////////////////////////////////////////////////////////////

    public function secure() : bool
    {
        return false;
    }

    public function readNonblock() : array
    {
        BayLog::debug("read ch=%s", $this->ch);

        $level = error_reporting();
        error_reporting(E_ERROR);
        $ret = fread($this->ch, $this->capacity);
        error_reporting($level);

        if($ret === false)
            throw new IOException("Cannot receive data: " . SysUtil::lastErrorMessage());
        return [$ret, null];
    }

    public function writeNonblock(string $buf, $adr) : int
    {
        BayLog::debug("write ch=%s", $this->ch);
        $ret = fwrite($this->ch, $buf);
        #$ret = stream_socket_sendto($this->ch, $buf);
        if($ret === false)
            throw new IOException("Cannot send data: " . SysUtil::lastErrorMessage());
        return $ret;
    }
}