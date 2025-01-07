<?php

namespace baykit\bayserver\util;

use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\Symbol;

class IOUtil
{

    private static $sock_buf_size = -1;

    public static function sendInt32($skt, int $i)
    {
        $data = pack("N", $i);
        $ret = socket_write($skt, $data);
        //fflush($ch);
        if ($ret === false || $ret != 4)
            throw new IOException("Send failed");
    }

    public static function writeInt32($ch, int $i)
    {
        $data = pack("N", $i);
        //$ret = stream_socket_sendto($ch, $data);
        $ret = fwrite($ch, $data);
        //fflush($ch);
        if ($ret === false || $ret != 4)
            throw new IOException("Send failed");
    }

    public static function recvInt32($ch) : int
    {
        if(self::isEof($ch))
            throw new EofException();
        //$ret = stream_socket_recvfrom($ch, 4);
        $ret = fread($ch, 4);
        //$ret = socket_read($ch, 4);
        if ($ret === false)
            throw new IOException("Recv failed");
        elseif (strlen($ret) != 4)
            throw new BlockingIOException();

        return ord($ret[0]) << 24 | (ord($ret[1]) << 16 & 0xFF0000) |
            (ord($ret[2]) << 8 & 0xFF00) | (ord($ret[3]) & 0xFF);
    }

    public static function openLocalPipe()
    {
        return stream_socket_pair(
            SysUtil::runOnWindows() ? STREAM_PF_INET : STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP);
    }

    public static function getSockRecvBufSize($skt) : int
    {
        if(self::$sock_buf_size == -1) {
            $dmy = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($dmy === false)
                throw new \Exception("Cannot create socket: " . socket_strerror(socket_last_error()));
            self::$sock_buf_size = socket_get_option($dmy, SOL_SOCKET, SO_RCVBUF);
            socket_close($dmy);
        }
        return self::$sock_buf_size;
    }

    static function isEof($ch) : bool
    {
        return stream_get_meta_data($ch)["eof"];
    }

}