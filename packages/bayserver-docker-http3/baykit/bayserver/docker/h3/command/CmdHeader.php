<?php
namespace baykit\bayserver\docker\h3\command;

/**
 * H3 HEADERS event payload.
 *
 * reqHeaders is the list pulled out of the croute H3 event:
 * `[ [name, value], [name, value],... ]`. Includes pseudo-headers
 * (":method", ":path", ":scheme", ":authority") which the inbound
 * handler validates against RFC 9114 §4.3 before dispatching.
 */
class CmdHeader
{
    public int $stmId;
    /** @var array<int, array{0:string,1:string}> */
    public array $reqHeaders;

    /**
     * @param int $stmId QUIC stream id (request id)
     * @param array<int, array{0:string,1:string}> $reqHeaders
     */
    public function __construct(int $stmId, array $reqHeaders)
    {
        $this->stmId = $stmId;
        $this->reqHeaders = $reqHeaders;
    }
}
