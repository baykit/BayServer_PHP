<?php
namespace baykit\bayserver\util;


class HostMatcher
{
    const MATCH_TYPE_ALL = 1;
    const MATCH_TYPE_EXACT = 2;
    const MATCH_TYPE_DOMAIN = 3;

    private $type;
    private $host;
    private $domain;

    public function __construct(string $host)
    {
        if ($host == "*") {
            $this->type = self::MATCH_TYPE_ALL;
        }
        elseif (StringUtil::startsWith($host, "*.")) {
            $this->type = self::MATCH_TYPE_DOMAIN;
            $this->domain = substr($host, 2);
        }
        else {
            $this->type = self::MATCH_TYPE_EXACT;
            $this->host = $host;
        }
    }


    public function match(string $remoteHost) : bool
    {
        if ($this->type == self::MATCH_TYPE_ALL) {
            // all match
            return true;
        }

        if ($remoteHost == null) {
            return false;
        }

        if ($this->type == self::MATCH_TYPE_EXACT) {
            // exact match
            return $remoteHost == $this->host;
        }
        else {
            // domain match
            return StringUtil::endsWith($remoteHost, $this->domain);
        }
    }
}

