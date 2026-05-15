<?php

declare(strict_types=1);

namespace phpverse\Protocol;

/**
 * FastCGI 1.1 protocol constants.
 *
 * Values are taken from the FastCGI 1.1 specification:
 * https://fastcgi-archives.github.io/FastCGI_Specification.html
 *
 * Grouped here in one place so that codec / worker code never embeds
 * raw wire numbers.
 */
final class Constants
{
    /** FCGI protocol version byte (header.version). */
    public const FCGI_VERSION_1 = 1;

    /** FCGI header size in bytes. The header is fixed-size on the wire. */
    public const FCGI_HEADER_LEN = 8;

    /** "Null" request id used for management records (GET_VALUES etc.). */
    public const FCGI_NULL_REQUEST_ID = 0;

    // ---------------------------------------------------------------
    // Record types (header.type)
    // ---------------------------------------------------------------

    public const FCGI_BEGIN_REQUEST     = 1;
    public const FCGI_ABORT_REQUEST     = 2;
    public const FCGI_END_REQUEST       = 3;
    public const FCGI_PARAMS            = 4;
    public const FCGI_STDIN             = 5;
    public const FCGI_STDOUT            = 6;
    public const FCGI_STDERR            = 7;
    public const FCGI_DATA              = 8;
    public const FCGI_GET_VALUES        = 9;
    public const FCGI_GET_VALUES_RESULT = 10;
    public const FCGI_UNKNOWN_TYPE      = 11;

    /** Largest record type value emitted by FCGI/1.1. */
    public const FCGI_MAXTYPE = self::FCGI_UNKNOWN_TYPE;

    // ---------------------------------------------------------------
    // Roles (BeginRequestBody.role, 16-bit big-endian)
    // ---------------------------------------------------------------

    public const FCGI_RESPONDER  = 1;
    public const FCGI_AUTHORIZER = 2;
    public const FCGI_FILTER     = 3;

    // ---------------------------------------------------------------
    // BeginRequestBody.flags
    // ---------------------------------------------------------------

    /**
     * If set on FCGI_BEGIN_REQUEST.flags the responder must not close the
     * transport connection after sending FCGI_END_REQUEST.
     */
    public const FCGI_KEEP_CONN = 1;

    // ---------------------------------------------------------------
    // EndRequestBody.protocolStatus
    // ---------------------------------------------------------------

    public const FCGI_REQUEST_COMPLETE = 0;
    public const FCGI_CANT_MPX_CONN    = 1;
    public const FCGI_OVERLOADED       = 2;
    public const FCGI_UNKNOWN_ROLE     = 3;

    // ---------------------------------------------------------------
    // Well-known management variable names (FCGI_GET_VALUES)
    // ---------------------------------------------------------------

    public const FCGI_MAX_CONNS  = 'FCGI_MAX_CONNS';
    public const FCGI_MAX_REQS   = 'FCGI_MAX_REQS';
    public const FCGI_MPXS_CONNS = 'FCGI_MPXS_CONNS';

    private function __construct()
    {
    }
}
