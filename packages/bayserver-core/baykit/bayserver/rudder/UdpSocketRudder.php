<?php
namespace baykit\bayserver\rudder;

use baykit\bayserver\Sink;

/**
 * Rudder for a UDP listening socket. UDP I/O does not flow through the
 * normal Rudder::read / Rudder::write pair: each datagram has its own
 * source / destination address, so SpiderMultiplexer dispatches
 * stream_socket_recvfrom and stream_socket_sendto directly when it
 * sees this rudder type. The read / write members below throw Sink so
 * any code path that forgets the UDP branch and falls through to the
 * generic TCP path fails loudly. Mirrors Python UdpSocketRudder.
 */
class UdpSocketRudder extends StreamRudder
{
    public function __construct($stream)
    {
        parent::__construct($stream);
    }

    public function __toString(): string
    {
        return "UdpSocketRudder(". $this->stream. ")";
    }

    public function read(int $len) : ?string
    {
        // UDP datagrams arrive complete; SpiderMultiplexer pulls them
        // with stream_socket_recvfrom() directly, which also yields the
        // sender address. Falling through to fread() loses the address.
        throw new Sink("UdpSocketRudder::read is not supported");
    }

    public function write(string $buf) : int
    {
        // UDP requires the destination address to come with each
        // sendto. The multiplexer pulls it from WriteUnit->adr; the
        // plain fwrite() path has no way to convey that.
        throw new Sink("UdpSocketRudder::write is not supported");
    }
}
