<?php
namespace baykit\bayserver\docker\h3;

use baykit\bayserver\protocol\Command;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;

/**
 * Dummy base class for H3 commands. QUIC frames are decoded inside
 * libquiche; the BayServer command pipeline does not see raw H3
 * frames. The unpack / pack / handle bodies
 * are no-ops because the protocol handler reaches inside the
 * croute event directly.
 */
abstract class QicCommand extends Command
{
    public function __construct(int $type)
    {
        parent::__construct($type);
    }

    public function unpack(Packet $pkt) : void {}
    public function pack(Packet $pkt) : void {}
    public function handle(CommandHandler $handler) : int { return 0; }
}
