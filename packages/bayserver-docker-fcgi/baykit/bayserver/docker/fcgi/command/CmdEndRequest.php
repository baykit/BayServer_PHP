<?php

namespace baykit\bayserver\docker\fcgi\command;


use baykit\bayserver\docker\fcgi\FcgCommand;
use baykit\bayserver\docker\fcgi\FcgType;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;
use baykit\bayserver\util\StringUtil;


/**
 * FCGI spec
 *   http://www.mit.edu/~yandros/doc/specs/fcgi-spec.html
 *
 * Endrequest command format
 *         typedef struct {
 *             unsigned char appStatusB3;
 *             unsigned char appStatusB2;
 *             unsigned char appStatusB1;
 *             unsigned char appStatusB0;
 *             unsigned char protocolStatus;
 *             unsigned char reserved[3];
 *         } FCGI_EndRequestBody;
 */
class CmdEndRequest extends FcgCommand
{
    const FCGI_REQUEST_COMPLETE = 0;
    const FCGI_CANT_MPX_CONN = 1;
    const FCGI_OVERLOADED = 2;
    const FCGI_UNKNOWN_ROLE = 3;

    public $appStatus = 0;
    public $protocolStatus = self::FCGI_REQUEST_COMPLETE;

    public function __construct(int $reqId)
    {
        parent::__construct(FcgType::END_REQUEST, $reqId);
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);

        $acc = $pkt->newDataAccessor();
        $this->appStatus = $acc->getInt();
        $this->protocolStatus = $acc->getByte();
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newDataAccessor();
        $acc->putInt($this->appStatus);
        $acc->putByte($this->protocolStatus);
        $acc->putBytes(StringUtil::allocate(3));

        // must be called from last line
        parent::pack($pkt);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleEndRequest($this);
    }

}