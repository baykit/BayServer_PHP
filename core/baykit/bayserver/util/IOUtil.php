<?php

namespace baykit\bayserver\util;

use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\Symbol;

class IOUtil
{

    private static $sock_buf_size = -1;

    public static function sendInt32($ch, int $i)
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
        $eof = stream_get_meta_data($ch)["eof"];
        if($eof)
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
        # Dynamic and/or Private Ports (49152-65535)
        # https://www.iana.org/assignments/service-names-port-numbers/service-names-port-numbers.xhtml
        /*
        $DYNAMIC_PORTS_START = 49152;
        for ($i = $DYNAMIC_PORTS_START; $i <= 65535; $i++) {
            try {
                return self::openLocalPipeByPort($i);
            } catch (\Throwable $e) {
                continue;
            }
        }
        return false;
        */
        return stream_socket_pair(
            SysUtil::runOnWindows() ? STREAM_PF_INET : STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP);
    }

    /*
    public static function openLocalPipeByPort(int $port_num) : array
    {
        BayLog::debug(BayMessage::get(Symbol::MSG_OPENING_LOCAL_PORT, $port_num));
        $localhost = "127.0.0.1";

        $server_skt = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!socket_set_option($server_skt, SOL_SOCKET, SO_REUSEADDR, 1))
            throw new \Exception("socket_set_option");
        if (!socket_bind($server_skt, $localhost, $port_num))
            throw new \Exception("socket_bind");
        if (!socket_listen($server_skt, 0))
            throw new \Exception("socket_listen");

        $source_skt = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!socket_connect($source_skt, $localhost, $port_num))
            throw new \Exception("socket_connect");

        if (!($sink_skt = socket_accept($server_skt)))
            throw new \Exception("socket_accept");

        BayLog::debug(BayMessage::get(Symbol::MSG_CLOSING_LOCAL_PORT, $port_num));
        socket_close($server_skt);

        socket_set_nonblock($source_skt);
        socket_set_nonblock($sink_skt);

        return [$sink_skt, $source_skt];
    }
    */




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



}