<?php
namespace baykit\bayserver\docker\h3;

use Croute\CrouteError;

/**
 * Convenience re-exports of the croute / quiche error codes we
 * branch on inside QicProtocolHandler and QicInboundHandler. The
 * source of truth is Croute\CrouteError; these aliases just save the
 * import noise.
 */
final class QuicheErrorCode
{
    const ERR_DONE                  = -1;
    const ERR_INVALID_STREAM_STATE  = CrouteError::ERR_INVALID_STREAM_STATE;
    const ERR_STREAM_STOPPED        = CrouteError::ERR_STREAM_STOPPED;

    const H3_ERR_DONE                = -1;
    const H3_ERR_FRAME_UNEXPECTED    = CrouteError::H3_ERR_FRAME_UNEXPECTED;
    const H3_ERR_TRANSPORT_ERROR     = CrouteError::H3_ERR_TRANSPORT_ERROR;
    const H3_ERR_STREAM_BLOCKED      = CrouteError::H3_ERR_STREAM_BLOCKED;
}
