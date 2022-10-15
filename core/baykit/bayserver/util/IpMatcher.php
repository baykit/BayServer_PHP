<?php
namespace baykit\bayserver\util;

use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\Symbol;

class IpMatcher
{
    private $matchAll;
    private $netAdrBytes; // byte array
    private $maskAdrBytes; // byte array

    public function __construct(string $ipDesc)
    {
        if ($ipDesc == "*")
            $this->matchAll = true;
        else
            $this->parseIp($ipDesc);
    }

    public function match(string $adr) : bool
    {
        if ($this->matchAll)
            return true;

        $adrBytes = $this->getIpAddr($adr);
        if($adrBytes === false) {
            BayLog::warn("Invalid IP address format: %s", $adr);
            return false;
        }

        if (count($adrBytes) != count($this->maskAdrBytes))
            return false;  // IPv4 and IPv6 don't match each other

        for ($i = 0; $i < count($adrBytes); $i++) {
            if (($adrBytes[$i] & $this->maskAdrBytes[$i]) != $this->netAdrBytes[$i])
                return false;
        }
        return true;
    }

    private function parseIp(string $ipDesc) : void
    {
        $items = explode("/", $ipDesc);
        $ip = null;
        $mask = null;
        if (count($items) == 0)
            throw new \InvalidArgumentException(
                BayMessage::get(Symbol::CFG_INVALID_IP_DESC, $ipDesc));

        $ip = $items[0];
        if (count($items) == 1)
            $mask = "255.255.255.255";
        else
            $mask = $items[1];

        $this->netAdrBytes = $this->getIpAddr($ip);
        $this->maskAdrBytes = $this->getIpAddr($mask);
        if (count($this->netAdrBytes) != count($this->maskAdrBytes)) {
            throw new \InvalidArgumentException(
                BayMessage::get(Symbol::CFG_IPV4_AND_IPV6_ARE_MIXED, $ipDesc));
        }
    }

    /**
     * Convert IP Address format
     *    string -> bytes[]
     */
    private function getIpAddr(string $ipAdress)
    {
        $ipAdrIn = inet_pton($ipAdress);
        if($ipAdrIn === false) {
            BayLog::warn("Invalid IP address format: %s", $ipAdress);
            return false;
        }

        return unpack("C*", $ipAdrIn);
    }
}

