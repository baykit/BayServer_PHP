<?php
namespace baykit\bayserver;

class Symbol
{
    # Parser Error
    const PAS_BRACE_NOT_CLOSED = "PAS_BRACE_NOT_CLOSED";
    const PAS_INVALID_INDENT = "PAS_INVALID_INDENT";
    const PAS_INVALID_LINE = "PAS_INVALID_LINE";
    const PAS_INVALID_WHITESPACE = "PAS_INVALID_WHITESPACE";

    # Configuration Error
    const CFG_INVALID_PARAMETER = "CFG_INVALID_PARAMETER";
    const CFG_INVALID_DOCKER_CLASS = "CFG_INVALID_DOCKER_CLASS";
    const CFG_INVALID_DESTINATION = "CFG_INVALID_DESTINATION";
    const CFG_NO_PORT_DOCKER = "CFG_NO_PORT_DOCKER";
    const CFG_INVALID_PORT_NAME = "CFG_INVALID_PORT_NAME";
    const CFG_LOCATION_NOT_FOUND = "CFG_LOCATION_NOT_FOUND";
    const CFG_LOCATION_NOT_DEFINED = "CFG_LOCATION_NOT_DEFINED";
    const CFG_INVALID_LOCATION = "CFG_INVALID_LOCATION";
    const CFG_INVALID_DOCKER = "CFG_INVALID_DOCKER";
    const CFG_DOCKER_NOT_FOUND = "CFG_DOCKER_NOT_FOUND";
    const CFG_INVALID_LOG_FORMAT = "CFG_INVALID_LOG_FORMAT";
    const CFG_INVALID_PERMISSION_DESCRIPTION = "CFG_INVALID_PERMISSION_DESCRIPTION";
    const CFG_GROUP_NOT_FOUND = "CFG_GROUP_NOT_FOUND";
    const CFG_FILE_NOT_FOUND = "CFG_FILE_NOT_FOUND";
    const CFG_WARP_DESTINATION_NOT_DEFINED = "CFG_WARP_DESTINATION_NOT_DEFINED";
    const CFG_INVALID_WARP_DESTINATION = "CFG_INVALID_WARP_DESTINATION";
    const CFG_INVALID_PARAMETER_VALUE = "CFG_INVALID_PARAMETER_VALUE";
    const CFG_SSL_INIT_ERROR = "CFG_SSL_INIT_ERROR";
    const CFG_SSL_KEY_FILE_NOT_SPECIFIED = "CFG_SSL_KEY_FILE_NOT_SPECIFIED";
    const CFG_SSL_CERT_FILE_NOT_SPECIFIED = "CFG_SSL_CERT_FILE_NOT_SPECIFIED";
    const CFG_INVALID_IP_DESC = "CFG_INVALID_IP_DESC";
    const CFG_IPV4_AND_IPV6_ARE_MIXED = "CFG_IPV4_AND_IPV6_ARE_MIXED";
    const CFG_PARAMETER_IS_NOT_A_NUMBER = "CFG_PARAMETER_IS_NOT_A_NUMBER";
    const CFG_MULTI_CORE_NOT_SUPPORTED = "CFG_MULTI_CORE_NOT_SUPPORTED";
    const CFG_SINGLE_CORE_NOT_SUPPORTED = "CFG_SINGLE_CORE_NOT_SUPPORTED";
    const CFG_FILE_SEND_METHOD_SELECT_NOT_SUPPORTED = "CFG_FILE_SEND_METHOD_SELECT_NOT_SUPPORTED";
    const CFG_FILE_SEND_METHOD_SPIN_NOT_SUPPORTED = "CFG_FILE_SEND_METHOD_SPIN_NOT_SUPPORTED";
    const CFG_LOG_WRITE_METHOD_SELECT_NOT_SUPPORTED = "CFG_LOG_WRITE_METHOD_SELECT_NOT_SUPPORTED";
    const CFG_LOG_WRITE_METHOD_SPIN_NOT_SUPPORTED = "CFG_LOG_WRITE_METHOD_SPIN_NOT_SUPPORTED";
    const CFG_MAX_SHIPS_IS_TO_SMALL = "CFG_MAX_SHIPS_IS_TO_SMALL";
    const CFG_TCP_NOT_SUPPORTED = "CFG_TCP_NOT_SUPPORTED";
    const CFG_UDP_NOT_SUPPORTED = "CFG_UDP_NOT_SUPPORTED";
    const CFG_CANNOT_SUPPORT_UNIX_DOMAIN_SOCKET = "CFG_CANNOT_SUPPORT_UNIX_DOMAIN_SOCKET";

    # HTTPERRORS
    const HTP_SENDING_HTTP_ERROR = "HTP_SENDING_HTTP_ERROR";
    const HTP_CANNOT_SUPPORT_HTTP2 = "HTP_CANNOT_SUPPORT_HTTP2";
    const HTP_INVALID_FIRST_LINE = "HTP_INVALID_FIRST_LINE";
    const HTP_UNSUPPORTED_PROTOCOL = "HTP_UNSUPPORTED_PROTOCOL";
    const HTP_INVALID_HEADER_FORMAT = "HTP_INVALID_HEADER_FORMAT";
    const HTP_INVALID_PREFACE = "HTP_INVALID_PREFACE";
    const HTTP_READ_DATA_EXCEEDED = "HTTP_READ_DATA_EXCEEDED";

    # Internal errors
    const INT_UNKNOWN_LOG_LEVEL = "INT_UNKNOWN_LOG_LEVEL";
    const INT_CANNOT_OPEN_LOG_FILE = "INT_CANNOT_OPEN_LOG_FILE";
    const INT_CANNOT_OPEN_PORT = "INT_CANNOT_OPEN_PORT";
    const INT_CANNOT_SET_SIG_HANDLER = "INT_CANNOT_SET_SIG_HANDLER";
    const INT_NO_MORE_TOURS = "INT_NO_MORE_TOURS";

    # Network errors
    const NET_NOT_ALLOWED_TO_CONNECT_FROM = "NET_NOT_ALLOWED_TO_CONNECT_FROM";

    # Messages
    const MSG_OPENING_LOCAL_PORT = "MSG_OPENING_LOCAL_PORT";
    const MSG_CLOSING_LOCAL_PORT = "MSG_CLOSING_LOCAL_PORT";
    const MSG_OPENING_TCP_PORT = "MSG_OPENING_TCP_PORT";
    const MSG_CLOSING_TCP_PORT = "MSG_CLOSING_TCP_PORT";
    const MSG_OPENING_UDP_PORT = "MSG_OPENING_UDP_PORT";
    const MSG_CLOSING_UDP_PORT = "MSG_CLOSING_UDP_PORT";
    const MSG_RUNNING_GRAND_AGENT = "MSG_RUNNING_GRAND_AGENT";
    const MSG_OPEN_CTL_PORT = "MSG_OPEN_CTL_PORT";
    const MSG_SETTING_UP_TOWN = "MSG_SETTING_UP_TOWN";
    const MSG_COMMAND_RECEIVED = "MSG_COMMAND_RECEIVED";
    const MSG_GRAND_AGENT_SHUTDOWN = "MSG_GRAND_AGENT_SHUTDOWN";

}