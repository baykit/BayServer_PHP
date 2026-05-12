<?php
namespace baykit\bayserver\docker\h3;

/**
 * Shared QUIC datagram size constants.
 */
final class QicPacket
{
    /**
     * Maximum UDP payload we offer / accept. MAX_DATAGRAM_SIZE = 1350 (= the quiche-recommended
     * conservative MTU; large enough to avoid IPv6 path-MTU issues
     * on the default loopback / Ethernet without risking fragmentation).
     */
    const MAX_DATAGRAM_SIZE = 1350;
}
