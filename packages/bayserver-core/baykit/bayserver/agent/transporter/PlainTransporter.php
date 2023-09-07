<?php

namespace baykit\bayserver\agent\transporter;

use baykit\bayserver\BayLog;
use baykit\bayserver\util\EofException;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\IOUtil;
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

        #$level = error_reporting();
        #error_reporting(E_ERROR);
        $ret = fread($this->ch, $this->capacity);
        #error_reporting($level);

        if($ret === false) {
            if(IOUtil::isEof($this->ch)) {
                BayLog::debug("%s Cannot receive data (EOF)", $this);
                $ret = "";
            }
            else {
                BayLog::debug("ch type=%s", get_resource_type($this->ch));
                BayLog::debug("err: %s", SysUtil::lastSocketErrorMessage());
                throw new IOException("Cannot receive data: " . SysUtil::lastErrorMessage());
            }
        }
        return [$ret, null];
    }

    public function writeNonblock(string $buf, $adr) : int
    {
        BayLog::debug("write ch=%s", $this->ch);
        #$level = error_reporting();
        #error_reporting(E_ERROR);
        $ret = fwrite($this->ch, $buf);
        #error_reporting($level);

        #$ret = stream_socket_sendto($this->ch, $buf);
        if($ret === false) {
            if(IOUtil::isEof($this->ch)) {
                throw new IOException($this . " Write failed (EOF)");
            }
            else if(SysUtil::lastSocketErrorMessage() == "Success") {
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