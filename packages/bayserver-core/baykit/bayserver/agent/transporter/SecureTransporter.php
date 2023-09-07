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
        if($ret === false) {
            while ($msg = openssl_error_string())
                BayLog::error("%s SSL Error: %s", $this, $msg);
            BayLog::error("%s Socket Error: %s", $this, SysUtil::lastSocketErrorMessage());
            BayLog::error("%s System Error: %s", $this, SysUtil::lastErrorMessage());
            throw new IOException("Cannot read data: " . SysUtil::lastErrorMessage());
        }
        return [$ret, null];
    }

    public function writeNonblock(string $buf, $adr) : int
    {
        #$ret = stream_socket_sendto($this->ch, $buf);
        $ret = fwrite($this->ch, $buf);
        if($ret === false) {
            if(SysUtil::lastSocketErrorMessage() == "Success") {
                // Will be retried (ad-hoc code)
                BayLog::debug("%s Write error (will be retried)", $this);
                $ret = 0;
            }
            else {
                while ($msg = openssl_error_string())
                    BayLog::error("%s SSL Error: %s", $this, $msg);
                BayLog::error("%s Socket Error: %s", $this, SysUtil::lastSocketErrorMessage());
                BayLog::error("%s System Error: %s", $this, SysUtil::lastErrorMessage());
                throw new IOException("Cannot write data: " . SysUtil::lastErrorMessage());
            }
        }
        return $ret;
    }
}