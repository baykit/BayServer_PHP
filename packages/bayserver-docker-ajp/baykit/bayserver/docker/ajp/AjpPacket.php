<?php

namespace baykit\bayserver\docker\ajp;

use baykit\bayserver\protocol\Packet;
use baykit\bayserver\protocol\PacketPartAccessor;
use baykit\bayserver\util\StringUtil;


/**
 * AJP Protocol
 * https://tomcat.apache.org/connectors-doc/ajp/ajpv13a.html
 *
 * AJP packet spec
 *
 *   packet:  preamble, length, body
 *   preamble:
 *        0x12, 0x34  (client->server)
 *     | 'A', 'B'     (server->client)
 *   length:
 *      2 byte
 *   body:
 *      $length byte
 *
 *
 *  Body format
 *    client->server
 *    Code     Type of Packet    Meaning
 *       2     Forward Request   Begin the request-processing cycle with the following data
 *       7     Shutdown          The web server asks the container to shut itself down.
 *       8     Ping              The web server asks the container to take control (secure login phase).
 *      10     CPing             The web server asks the container to respond quickly with a CPong.
 *    none     Data              Size (2 bytes) and corresponding body data.
 *
 *    server->client
 *    Code     Type of Packet    Meaning
 *       3     Send Body Chunk   Send a chunk of the body from the servlet container to the web server (and presumably, onto the browser).
 *       4     Send Headers      Send the response headers from the servlet container to the web server (and presumably, onto the browser).
 *       5     End Response      Marks the end of the response (and thus the request-handling cycle).
 *       6     Get Body Chunk    Get further data from the request if it hasn't all been transferred yet.
 *       9     CPong Reply       The reply to a CPing request
 *
 */
class AjpPacketPartAccessor extends PacketPartAccessor
{

    public function __construct(Packet $pkt, int $start, int $maxLen)
    {
        parent::__construct($pkt, $start, $maxLen);
    }

    public function putString(string $str) : void
    {
        if (StringUtil::isEmpty($str)) {
            $this->putShort(0xffff);
        }
        else {
            $this->putShort(strlen($str));
            parent::putString($str);
            $this->putByte(0); // null terminator
        }
    }

    public function getString() : string
    {
        return $this->getStringByLen($this->getShort());
    }

    public function getStringByLen(int $len) : string
    {

        if ($len == 0xffff) {
            return "";
        }

        $buf = $this->getBytes($len);
        $this->getByte(); // null terminator

        return $buf;
    }
}


class AjpPacket extends Packet {

    const PREAMBLE_SIZE = 4;
    const MAX_DATA_LEN = 8192 - self::PREAMBLE_SIZE;
    const MIN_BUF_SIZE = 1024;

    public $toServer;

    public function __construct(int $type) {
        parent::__construct($type, self::PREAMBLE_SIZE, self::MAX_DATA_LEN);
    }

    public function __toString() : string
    {
        return "AjpPacket({$this->type})";
    }

    public function reset() : void
    {
        $this->toServer = false;
        parent::reset();
    }

    public function newAjpHeaderAccessor() : AjpPacketPartAccessor
    {
        return new AjpPacketPartAccessor($this, 0, self::PREAMBLE_SIZE);
    }

    public function newAjpDataAccessor() : AjpPacketPartAccessor
    {
        return new AjpPacketPartAccessor($this, self::PREAMBLE_SIZE, -1);
    }

}