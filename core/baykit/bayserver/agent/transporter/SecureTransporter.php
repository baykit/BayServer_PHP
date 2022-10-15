<?php

namespace baykit\bayserver\agent\transporter;

use baykit\bayserver\BayLog;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\SysUtil;

class SecureTransporter extends Transporter
{
    public $sslctx;

    public function __construct($sslctx, bool $serverMode, int $bufsize, bool $traceSsl)
    {
        parent::__construct($serverMode, $bufsize, $traceSsl);
        $this->sslctx = $sslctx;
    }

    public function init($nbHnd, $ch, $lis) : void
    {
        parent::init($nbHnd, $ch, $lis);
        $this->handshaked = true;
    }

    public function __toString() : string
    {
        return "stp[{$this->dataListener}]";
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
        $ret = fread($this->ch, $this->capacity);
        if($ret === false)
            throw new IOException("Cannot receive data");
        return [$ret, null];
    }

    public function writeNonblock(string $buf, $adr) : int
    {
        #$ret = stream_socket_sendto($this->ch, $buf);
        $ret = fwrite($this->ch, $buf);
        if($ret === false || $ret == -1) {
            //throw new IOException("Cannot send data: " . socket_strerror(socket_last_error()));
            BayLog::warn("Cannot send data: %s", SysUtil::lastErrorMessage());
            return 0;
        }
        return $ret;
    }
}