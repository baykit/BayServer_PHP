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

    /**
     * Per-multiplexer state slot. Set by MultiplexerBase.addRudderState
     * and consulted by getRudderState. state
     *: keeping the state on the rudder itself removes the
     * per-multiplexer hashmap keyed by resource id, and removes the
     * stateId / checkStateId dedup that earlier guarded against stale
     * letters whose state had been Returned to the pool.
     */
    public $state = null;

    public function __construct($stream)
    {
        if(!is_resource($stream))
            throw new \InvalidArgumentException(get_class($stream));
        $this->stream = $stream;
    }

    public function __toString(): string
    {
        return "StreamRudder(". $this->stream. ")";
    }

    public function key()
    {
        return $this->stream;
    }

    public function setNonBlocking(): void
    {
        if (stream_set_blocking($this->stream, false) === false) {
            throw new IOException("Cannot set nonblock: ". SysUtil::lastErrorMessage());
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
                throw new IOException($this. " Read failed: ".  $error['message']);
            }
        }

        return $ret;
    }


    public function write(string $buf) : int
    {
        $ret = fwrite($this->stream, $buf);

        if($ret === false) {
            $error = error_get_last();
            throw new IOException($this. " Write failed: ".  $error['message']);
        }

        return $ret;
    }

    public function close(): void
    {
        if (!is_resource($this->stream)) {
            BayLog::error("Stream is already closed: %s", $this->stream);
            return;
        }
        // Half-close the write side before fclose so the peer sees a
        // proper TCP FIN. Without this, fclose() on a socket whose
        // kernel receive buffer still holds unread bytes (e.g. h2spec
        // sent an oversized DATA / HEADERS frame and we rejected it
        // after only reading the 9-byte frame header) triggers an
        // RFC-1122-mandated RST on Linux. The RST also discards any
        // GOAWAY frame we wrote moments earlier, so the peer never sees
        // our error frame. STREAM_SHUT_WR puts the socket in FIN_WAIT
        // first and lets the kernel deliver our pending writes before
        // close() runs -- mirrors Python SocketRudder.close (which does
        // skt.shutdown(SHUT_WR) for the same reason). Errors are
        // suppressed because the peer may have already closed.
        @stream_socket_shutdown($this->stream, STREAM_SHUT_WR);
        $ret = fclose($this->stream);
        if ($ret === false)
            BayLog::error("Cannot close channel: %s(%s)", $this->stream, SysUtil::lastErrorMessage());
    }
}
