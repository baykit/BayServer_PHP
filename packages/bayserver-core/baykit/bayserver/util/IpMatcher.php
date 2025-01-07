<?php
namespace baykit\bayserver\util;

use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\Symbol;

class IpMatcher
{
    private bool $matchAll = false;
    private string $netAdr;
    private int $mask;
    private bool $isV6;

    public function __construct(string $ipDesc)
    {
        if ($ipDesc == "*")
            $this->matchAll = true;
        else
            $this->parseCidr($ipDesc);
    }

    public function match(string $adr) : bool
    {
        if ($this->matchAll)
            return true;

        if($this->isV6) {
            if(!filter_var($adr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                // IPv4 and IPv6 don't match each other
                return false;
            }
            return $this->matchIPv6($adr);
        }
        else {
            if(!filter_var($adr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                // IPv4 and IPv6 don't match each other
                return false;
            }
            return $this->matchIPv4($adr);
        }
    }

    private function parseCidr(string $ipDesc) : void
    {
        $items = explode("/", $ipDesc);
        if (count($items) != 2)
            throw new \InvalidArgumentException(
                BayMessage::get(Symbol::CFG_INVALID_IP_DESC, $ipDesc));

        $this->netAdr = $items[0];
        $this->mask = (int)$items[1];
        BayLog::debug("adr=%s mask=%d", $this->netAdr, $this->mask);

        if(filter_var($this->netAdr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // IPv4
            $this->isV6 = false;
            if($this->mask < 0 || $this->mask > 32) {
                throw new \InvalidArgumentException(
                    BayMessage::get(Symbol::CFG_INVALID_IP_DESC, $ipDesc));
            }
        }
        else if(filter_var($this->netAdr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // IPv6
            $this->isV6 = true;
            if($this->mask < 0 || $this->mask > 128) {
                throw new \InvalidArgumentException(
                    BayMessage::get(Symbol::CFG_INVALID_IP_DESC, $ipDesc));
            }
        }
        else {
            throw new \InvalidArgumentException(
                BayMessage::get(Symbol::CFG_INVALID_IP_DESC, $ipDesc));
        }
    }

    private function matchIPv4(string $ip): bool
    {
        $ipBin = ip2long($ip);
        $netBin = ip2long($this->netAdr);
        $maskBin = -1 << (32 - $this->mask);
        return ($ipBin & $maskBin) === ($netBin & $maskBin);
    }

    private function matchIPv6(string $ip): bool
    {
        $ipBin = inet_pton($ip);
        $netBin = inet_pton($this->netAdr);

        $maskBin = str_repeat("\xff", (int)($this->mask / 8));
        if ($this->mask % 8) {
            $maskBin .= chr(0xff << (8 - ($this->mask % 8)));
        }
        $maskBin = str_pad($maskBin, strlen($ipBin), "\0");

        return ($ipBin & $maskBin) === ($netBin & $maskBin);
    }

}

