<?php
namespace baykit\bayserver\docker\h3;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\common\RudderStateStore;
use baykit\bayserver\ConfigException;
use baykit\bayserver\docker\base\PortBase;
use baykit\bayserver\docker\built_in\BuiltInSecureDocker;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\docker\Harbor;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\Symbol;
use Croute\H3\Config as H3Config;
use Croute\Quic\Config as QuicConfig;

/**
 * Port docker for HTTP/3. UDP listener + QicTransporter; spins up
 * a server-side Croute (libquiche) configuration during init().
 *
 */
class H3PortDocker extends PortBase implements H3Docker
{
    /** ALPN identifiers we advertise to clients. */
    public static array $appProtocols = ["h3", "h3-29", "h3-28", "h3-27"];

    public ?QuicConfig $quicConfig = null;
    public ?H3Config $h3Config = null;

    //////////////////////////////////////////////////////
    // Implements Docker
    //////////////////////////////////////////////////////

    public function init(BcfElement $elm, ?Docker $parent) : void
    {
        parent::init($elm, $parent);

        if ($this->secureDocker === null) {
            throw new ConfigException($elm->fileName, $elm->lineNo,
                "H3 port requires a [secure] docker with certFile and keyFile");
        }

        $cert = ($this->secureDocker instanceof BuiltInSecureDocker)
            ? $this->secureDocker->certFile : null;
        $key  = ($this->secureDocker instanceof BuiltInSecureDocker)
            ? $this->secureDocker->keyFile : null;
        if ($cert === null || $key === null) {
            throw new ConfigException($elm->fileName, $elm->lineNo,
                BayMessage::get(Symbol::CFG_SSL_CERT_FILE_NOT_SPECIFIED));
        }

        // Build ALPN wire format: each proto prefixed with its length byte.
        $alpnWire = "";
        foreach (self::$appProtocols as $p) {
            $alpnWire.= chr(strlen($p)). $p;
        }

        $this->quicConfig = QuicConfig::server($cert, $key);
        $this->quicConfig->setApplicationProtos($alpnWire);
        $this->quicConfig->setMaxIdleTimeout(5000);
        $this->quicConfig->setMaxRecvUdpPayloadSize(QicPacket::MAX_DATAGRAM_SIZE);
        $this->quicConfig->setMaxSendUdpPayloadSize(QicPacket::MAX_DATAGRAM_SIZE);
        $this->quicConfig->setInitialMaxData(10_000_000);
        $this->quicConfig->setInitialMaxStreamDataBidiLocal(1_000_000);
        $this->quicConfig->setInitialMaxStreamDataBidiRemote(1_000_000);
        $this->quicConfig->setInitialMaxStreamDataUni(1_000_000);
        $this->quicConfig->setInitialMaxStreamsBidi(4);
        $this->quicConfig->setInitialMaxStreamsUni(4);
        $this->quicConfig->setDisableActiveMigration(true);
        $this->quicConfig->enableEarlyData();

        $this->h3Config = new H3Config();
    }

    //////////////////////////////////////////////////////
    // Implements Port
    //////////////////////////////////////////////////////

    public function protocol() : string
    {
        return self::PROTO_NAME;
    }

    //////////////////////////////////////////////////////
    // Implements PortBase
    //////////////////////////////////////////////////////

    protected function supportAnchored() : bool { return false; }
    protected function supportUnanchored() : bool { return true; }

    public function onConnected(int $agentId, Rudder $rd) : void
    {
        // Mirrors the upstream H3PortDocker.onConnected. The accepted UDP rudder
        // is registered with the agent's net multiplexer and immediately
        // armed for read; the QicTransporter takes over demux from
        // there.
        $tp = new QicTransporter();

        $st = RudderStateStore::getStore($agentId)->rent();
        $st->init($rd, $tp);

        $agt = GrandAgent::get($agentId);
        $tp->initUdp($agentId, $rd, $agt->spiderMultiplexer, $this);
        $agt->spiderMultiplexer->addRudderState($rd, $st);
        $agt->spiderMultiplexer->reqRead($rd);
    }
}
