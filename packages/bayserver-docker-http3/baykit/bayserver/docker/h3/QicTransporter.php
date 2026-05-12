<?php
namespace baykit\bayserver\docker\h3;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\common\InboundShip;
use baykit\bayserver\common\Multiplexer;
use baykit\bayserver\common\Transporter;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\rudder\Rudder;
use Croute\CrouteError;
use Croute\Quic\Address;
use Croute\Quic\Connection as QuicConnection;
use Croute\Quic\PacketHeader;
use Croute\Quic\Quiche;

/**
 * Demultiplexer for incoming QUIC packets on a single UDP socket.
 *
 * Each datagram is inspected by {@link PacketHeader::parse} to
 * extract the DCID; the DCID is used as a key into an in-process
 * connection map that points at a per-connection InboundShip +
 * QicProtocolHandler. New flows go through Version Negotiation
 * (unsupported version) or stateless Retry (empty token) before an
 * accept(); subsequent packets feed straight into the
 * QicProtocolHandler::bytesReceived path.
 *
PHP is
 * single-threaded per agent so the connection map needs no lock.
 */
class QicTransporter implements Transporter
{
    /** Connection ID length we hand out in Retry responses. */
    const CONN_ID_LEN = 16;
    /** Scratch buffer size for version-negotiation / retry synthesis. */
    const MAX_PKT_BUF = 1350;

    public ?int $agentId = null;
    public ?Rudder $rudder = null;
    public ?Multiplexer $multiplexer = null;
    public ?H3PortDocker $portDkr = null;
    public ?Address $localAddr = null;

    /** @var array<string, InboundShip> hex(DCID) -> ship */
    private array $shipMap = [];

    /** HMAC seed for stateless retry tokens. */
    private string $serverNameBytes;

    /** Scratch for the next outgoing packet awaiting flush. */
    private ?string $tmpPostPkt = null;
    private ?string $tmpPostAddr = null;

    public function __construct()
    {
        $this->serverNameBytes = BayServer::getSoftwareName();
    }

    public function __toString() : string
    {
        return "agt#{$this->agentId} udp";
    }

    public function initUdp(int $agentId, Rudder $rd, Multiplexer $mpx, H3PortDocker $portDkr) : void
    {
        $this->agentId    = $agentId;
        $this->rudder     = $rd;
        $this->multiplexer = $mpx;
        $this->portDkr    = $portDkr;
        $this->localAddr  = new Address("0.0.0.0", $portDkr->port);
    }

    //////////////////////////////////////////
    // Implements Transporter
    //////////////////////////////////////////

    public function init() : void {}

    public function onConnected(Rudder $rd) : int
    {
        // UDP is connectionless.
        return NextSocketAction::CONTINUE;
    }

    /**
     * Called by SpiderMultiplexer after a UDP datagram has been
     * recvfrom'd. $adr is the "host:port" string from
     * stream_socket_recvfrom.
     */
    public function onRead(Rudder $rd, string $data, ?string $adr) : int
    {
        if ($adr === null) {
            BayLog::debug("%s onRead missing peer addr (drop)", $this);
            return NextSocketAction::CONTINUE;
        }
        BayLog::trace("%s notifyRead %d bytes from %s", $this, strlen($data), $adr);

        try {
            $hdr = PacketHeader::parse($data, strlen($data), self::CONN_ID_LEN);
        } catch (CrouteError $e) {
            BayLog::error("%s Header parse error: %s(%d)", $this, $e->getMessage(), $e->errCode);
            return NextSocketAction::CONTINUE;
        }

        BayLog::debug("%s packet received type=%d version=%d", $this, $hdr->type, $hdr->version);

        $sip = $this->findShip($hdr->dcid);
        if ($sip === null) {
            if ($hdr->type !== QicType::INITIAL) {
                BayLog::warn("Client not registered (type=%d)", $hdr->type);
            } else {
                $sip = $this->createShip($hdr, $adr);
            }
        }

        if ($sip !== null) {
            $sip->notifyRead($data);
        }

        $this->postAllPackets();
        $this->cleanupConnections();
        return NextSocketAction::CONTINUE;
    }

    public function onError(Rudder $rd, \Exception $e)
    {
        BayLog::error_e($e);
    }

    public function onClosed(Rudder $rd) {}

    public function reqConnect(Rudder $rd, string $addr) : void
    {
        throw new \baykit\bayserver\Sink("H3 server does not initiate connections");
    }

    public function reqRead(Rudder $rd) : void
    {
        $this->multiplexer->reqRead($rd);
    }

    public function reqWrite(Rudder $rd, string $buf, ?string $adr, $tag, bool $flush, ?callable $callback) : bool
    {
        return $this->multiplexer->reqWrite($rd, $buf, $adr, $tag, $flush, $callback);
    }

    public function reqTransfer(Rudder $rd, Rudder $fileRd, int $ofs, int $len, ?callable $callback) : void
    {
        throw new \baykit\bayserver\Sink("H3 has no transferContent path");
    }

    public function reqClose(Rudder $rd) : void
    {
        $this->multiplexer->reqClose($rd);
    }

    public function checkTimeout(Rudder $rd, int $durationSec) : bool { return false; }

    public function getReadBufferSize() : int { return self::MAX_PKT_BUF; }

    public function secure() : bool { return false; }

    public function printUsage(int $indent) : void {}

    public function reset() : void {}

    //////////////////////////////////////////
    // Private
    //////////////////////////////////////////

    private function createShip(PacketHeader $hdr, string $adr) : ?InboundShip
    {
        if (!Quiche::versionIsSupported($hdr->version)) {
            $this->negotiateVersion($hdr, $adr);
            return null;
        }

        if ($hdr->token === "") {
            $this->retryConnection($hdr, $adr);
            return null;
        }

        $peerIp = self::extractIp($adr);
        $odcid = $this->validateToken($hdr->token, $peerIp);
        if ($odcid === null) {
            BayLog::error("%s Invalid address validation token", $this);
            return null;
        }

        $scid = $hdr->dcid; // reuse DCID from our Retry response
        $peerAddr = self::makeAddress($adr);

        try {
            $con = QuicConnection::accept($scid, $odcid, $this->localAddr, $peerAddr, $this->portDkr->quicConfig);
        } catch (CrouteError $e) {
            BayLog::error("%s accept failed: %s", $this, $e->getMessage());
            return null;
        }

        BayLog::info("%s New connection scid=%s", $this, bin2hex($scid));

        $h3Config = $this->portDkr->h3Config;
        $inboundHandler = new QicInboundHandler();
        $protoHnd = new QicProtocolHandler($inboundHandler, $con, $adr, $this->localAddr, $peerAddr, $h3Config, $this->multiplexer);

        $sip = new InboundShip();
        $sip->initInbound($this->rudder, $this->agentId, $this, $this->portDkr, $protoHnd);
        $protoHnd->ship = $sip;

        $this->shipMap[bin2hex($scid)] = $sip;
        return $sip;
    }

    private function findShip(string $dcid) : ?InboundShip
    {
        return $this->shipMap[bin2hex($dcid)] ?? null;
    }

    /**
     * Stateless retry token: server_name_bytes + peer_ip_bytes + dcid.
mintToken.
     */
    private function mintToken(PacketHeader $hdr, string $peerIp) : string
    {
        $addr = @inet_pton($peerIp);
        if ($addr === false) $addr = $peerIp;
        return $this->serverNameBytes. $addr. $hdr->dcid;
    }

    /**
     * Verify a retry token previously minted by us, returning the
     * original DCID. Returns null when the token is invalid.
     */
    private function validateToken(string $token, string $peerIp) : ?string
    {
        $n = strlen($this->serverNameBytes);
        if (strlen($token) <= $n) return null;
        if (substr($token, 0, $n) !== $this->serverNameBytes) return null;

        $addr = @inet_pton($peerIp);
        if ($addr === false) $addr = $peerIp;
        $rest = substr($token, $n);
        $alen = strlen($addr);
        if (strlen($rest) < $alen) return null;
        if (substr($rest, 0, $alen) !== $addr) return null;
        return substr($rest, $alen);
    }

    private function negotiateVersion(PacketHeader $hdr, string $adr) : void
    {
        BayLog::info("%s Invalid quic version: %d. Version negotiation", $this, $hdr->version);
        $out = str_repeat("\0", self::MAX_PKT_BUF);
        $n = Quiche::negotiateVersion($hdr->scid, $hdr->dcid, $out);
        $this->tmpPostPkt = substr($out, 0, $n);
        $this->tmpPostAddr = $adr;
    }

    private function retryConnection(PacketHeader $hdr, string $adr) : void
    {
        $newScid = random_bytes(self::CONN_ID_LEN);
        $peerIp = self::extractIp($adr);
        BayLog::info("%s Empty quic token. Retry scid=%s dcid=%s newid=%s",
            $this, bin2hex($hdr->scid), bin2hex($hdr->dcid), bin2hex($newScid));

        $token = $this->mintToken($hdr, $peerIp);
        $out = str_repeat("\0", self::MAX_PKT_BUF);
        $n = Quiche::retry($hdr->scid, $hdr->dcid, $newScid, $token, $hdr->version, $out);
        $this->tmpPostPkt = substr($out, 0, $n);
        $this->tmpPostAddr = $adr;
    }

    /**
     * Flush the version-negotiation / retry packet (if any) and then
     * each per-connection outgoing queue. post_all_packets.
     */
    private function postAllPackets() : void
    {
        if ($this->tmpPostPkt !== null) {
            $pkt = $this->tmpPostPkt;
            $adr = $this->tmpPostAddr;
            $this->tmpPostPkt = null;
            $this->tmpPostAddr = null;
            $this->multiplexer->reqWrite($this->rudder, $pkt, $adr, $pkt, true, function ($avail) {});
        }
        foreach ($this->shipMap as $sip) {
            $proto = $sip->protocolHandler;
            if ($proto instanceof QicProtocolHandler) {
                $proto->postPackets();
            }
        }
    }

    private function cleanupConnections() : void
    {
        foreach ($this->shipMap as $key => $sip) {
            $proto = $sip->protocolHandler;
            if ($proto instanceof QicProtocolHandler && $proto->closed()) {
                BayLog::debug("%s cleaning up conn=%s", $this, $key);
                unset($this->shipMap[$key]);
            }
        }
    }

    private static function extractIp(string $adr) : string
    {
        // stream_socket_recvfrom hands back "host:port" for IPv4
        // and "[host]:port" for IPv6. Pull just the host part.
        if ($adr === "") return "";
        if ($adr[0] === '[') {
            $end = strpos($adr, ']');
            if ($end === false) return $adr;
            return substr($adr, 1, $end - 1);
        }
        $pos = strrpos($adr, ':');
        return $pos === false ? $adr : substr($adr, 0, $pos);
    }

    private static function extractPort(string $adr) : int
    {
        if ($adr === "") return 0;
        if ($adr[0] === '[') {
            $end = strpos($adr, ']');
            if ($end === false || !isset($adr[$end + 2])) return 0;
            return intval(substr($adr, $end + 2));
        }
        $pos = strrpos($adr, ':');
        if ($pos === false) return 0;
        return intval(substr($adr, $pos + 1));
    }

    private static function makeAddress(string $adr) : Address
    {
        return new Address(self::extractIp($adr), self::extractPort($adr));
    }
}
