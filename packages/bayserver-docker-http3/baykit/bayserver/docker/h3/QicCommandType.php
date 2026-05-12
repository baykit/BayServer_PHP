<?php
namespace baykit\bayserver\docker\h3;

/**
 * Logical H3 event types we route through the command handler.
Values do not appear on the wire
 * they only tag the synthesised CmdHeader / CmdData / CmdFinished
 * objects we hand to QicInboundHandler.
 */
final class QicCommandType
{
    const HEADERS  = 1;
    const DATA     = 2;
    const FINISHED = 3;
}
