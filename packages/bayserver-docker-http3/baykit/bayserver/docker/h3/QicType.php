<?php
namespace baykit\bayserver\docker\h3;

/**
 * QUIC long-header packet types (RFC 9000 §17.2). Used by the
 * QicTransporter when it inspects an inbound packet header to
 * decide whether to do version negotiation, stateless retry, or
 * route to an existing connection.
 */
final class QicType
{
    const INITIAL              = 1;
    const RETRY                = 2;
    const HANDSHAKE            = 3;
    const ZERO_RTT             = 4;
    const SHORT                = 5;
    const VERSION_NEGOTIATION  = 6;
}
