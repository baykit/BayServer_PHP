<?php

namespace baykit\bayserver\docker\fcgi\command;


use baykit\bayserver\docker\fcgi\FcgCommand;
use baykit\bayserver\docker\fcgi\FcgType;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\StringUtil;


/**
 * FCGI spec
 *   http://www.mit.edu/~yandros/doc/specs/fcgi-spec.html
 *
 * Begin request command format
 *         typedef struct {
 *             unsigned char roleB1;
 *             unsigned char roleB0;
 *             unsigned char flags;
 *             unsigned char reserved[5];
 *         } FCGI_BeginRequestBody;
 */
class CmdBeginRequest extends FcgCommand
{
    const FCGI_KEEP_CONN = 1;
    const FCGI_RESPONDER = 1;
    const FCGI_AUTHORIZER = 2;
    const FCGI_FILTER = 3;

    public $role;
    public $keepConn;

    public function __construct(int $reqId)
    {
        parent::__construct(FcgType::BEGIN_REQUEST, $reqId);
        $this->headers = new Headers();
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);

        $acc = $pkt->newDataAccessor();
        $this->role = $acc->getShort();
        $flags = $acc->getByte();
        $this->keepConn = ($flags & self::FCGI_KEEP_CONN) != 0;
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newDataAccessor();
        $acc->putShort($this->role);
        $acc->putByte($this->keepConn ? 1 : 0);
        $acc->putBytes(StringUtil::allocate(5));

        // must be called from last line
        parent::pack($pkt);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleBeginRequest($this);
    }

    private function readRequestHeaders(AjpPacketPartAccessor $acc) : void
    {
        $count = $acc->getShort();
        for ($i = 0; $i < $count; $i++) {
            $code = $acc->getShort();
            if ($code >= 0xA000) {
                if(!array_key_exists($code, self::$wellKnownHeaders))
                    throw new ProtocolException("Invalid header");
                $name = self::$wellKnownHeaders[$code];
            }
            else {
                $name = $acc->getStringByLen($code);
            }
            $value = $acc->getString();
            $this->headers->add($name, $value);
            //BayLog.debug("ajp: ForwardRequest header:" + name + ":" + value);
        }
    }
}