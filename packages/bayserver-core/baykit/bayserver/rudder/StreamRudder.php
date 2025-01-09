<?php
namespace baykit\bayserver\rudder;

use baykit\bayserver\agent\transporter\DataListener;
use baykit\bayserver\BayLog;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Sink;
use baykit\bayserver\util\Counter;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\IOUtil;
use baykit\bayserver\util\SysUtil;

class StreamRudder implements Rudder
{
    public $stream;

    public function __construct($stream)
    {
        if(!is_resource($stream))
            throw new \InvalidArgumentException(get_class($stream));
        $this->stream = $stream;
    }

    public function __toString(): string
    {
        return "StreamRudder(" . $this->stream . ")";
    }

    public function key()
    {
        return $this->stream;
    }

    public function setNonBlocking(): void
    {
        if (stream_set_blocking($this->stream, false) === false) {
            throw new IOException("Cannot set nonblock: " . SysUtil::lastErrorMessage());
        }
    }

    // Returns "" when reached EOF
    public function read(int $len) : ?string
    {
        $ret = fread($this->stream, $len);

        if($ret === false) {
            if(IOUtil::isEof($this->stream)) {
                BayLog::debug("%s Cannot receive data (EOF)", $this);
                $ret = "";
            }
            else {
                $error = error_get_last();
                throw new IOException($this . " Read failed: " .  $error['message']);
            }
        }

        return $ret;
    }


    public function write(string $buf) : int
    {
        $ret = fwrite($this->stream, $buf);

        if($ret === false) {
            $error = error_get_last();
            throw new IOException($this . " Write failed: " .  $error['message']);
        }

        return $ret;
    }

    public function close(): void
    {
        if (!is_resource($this->stream)) {
            BayLog::error("Stream is already closed: %s", $this->stream);
        }
        else {
            $ret = fclose($this->stream);
            if($ret === false)
                BayLog::error("Cannot close channel: %s(%s)", $this->stream, SysUtil::lastErrorMessage());
        }
    }
}
