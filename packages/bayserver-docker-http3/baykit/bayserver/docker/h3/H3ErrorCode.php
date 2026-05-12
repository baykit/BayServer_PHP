<?php
namespace baykit\bayserver\docker\h3;

/**
 * Selected H3 application error codes from RFC 9114 §8.1. Mirrors
 * the upstream H3ErrorCode. Only the few we actually emit are listed
 * anything else surfaces as Croute\CrouteError::$errCode.
 */
final class H3ErrorCode
{
    /** No error -- graceful close. */
    const NO_ERROR             = 0x100;
    /** Server-side internal error (rare; mostly used in tests). */
    const INTERNAL_ERROR       = 0x102;
    /** Malformed request / response (e.g. pseudo-header violation). */
    const MESSAGE_ERROR        = 0x10E;
}
