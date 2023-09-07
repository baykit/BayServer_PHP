<?php

namespace baykit\bayserver\docker\fcgi;

use baykit\bayserver\protocol\Packet;



/**
 * FCGI spec
 *   http://www.mit.edu/~yandros/doc/specs/fcgi-spec.html
 *
 * FCGI Packet (Record) format
 *         typedef struct {
 *             unsigned char version;
 *             unsigned char type;
 *             unsigned char requestIdB1;
 *             unsigned char requestIdB0;
 *             unsigned char contentLengthB1;
 *             unsigned char contentLengthB0;
 *             unsigned char paddingLength;
 *             unsigned char reserved;
 *             unsigned char contentData[contentLength];
 *             unsigned char paddingData[paddingLength];
 *         } FCGI_Record;
 */
class FcgPacket extends Packet {

    const PREAMBLE_SIZE = 8;

    const VERSION = 1;
    const MAXLEN = 65535;

    const FCGI_NULL_REQUEST_ID = 0;
    public $version = self::VERSION;
    public $reqId;


    public function __construct(int $type) {
        parent::__construct($type, self::PREAMBLE_SIZE, self::MAXLEN);
    }

    public function __toString() : string
    {
        return "FcgPacket({$this->type}) id={$this->reqId}";
    }

    public function reset() : void
    {
        $this->version = self::VERSION;
        $this->reqId = 0;
        parent::reset();
    }
}