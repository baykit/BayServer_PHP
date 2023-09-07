<?php

namespace baykit\bayserver\docker\http\h2;


use baykit\bayserver\util\ArrayUtil;
use baykit\bayserver\util\KeyVal;

class HeaderTable
{
    const PSEUDO_HEADER_AUTHORITY = ":authority";
    const PSEUDO_HEADER_METHOD = ":method";
    const PSEUDO_HEADER_PATH = ":path";
    const PSEUDO_HEADER_SCHEME = ":scheme";
    const PSEUDO_HEADER_STATUS = ":status";

    static $staticTable;
    static $staticSize;

    private $idxMap = [];
    private $addCount = 0;
    private $nameMap = [];

    public function get(int $idx) : KeyVal
    {
        if($idx <= 0 || $idx > self::$staticSize + count($this->idxMap))
            throw new \InvalidArgumentException("idx={$idx} static={self::$staticSize} dynamic={count($this->idxMap)}");

        if($idx <= self::$staticSize)
            $kv = self::$staticTable->idxMap[$idx - 1];
        else
            $kv = $this->idxMap[($idx - self::$staticSize) - 1];
        return $kv;
    }

    public function getIdxList(string $name) : array
    {
        $dynamicList = array_key_exists($name, $this->nameMap) ? $this->nameMap[$name] : null;
        $staticList = array_key_exists($name, self::$staticTable->nameMap) ? self::$staticTable->nameMap[$name] : null;

        $idxList = [];
        if ($staticList != null)
            $idxList = array_merge([], $staticList);
        if ($dynamicList != null) {
            foreach ($dynamicList as $idx) {
                $realIndex = $this->addCount - $idx + self::$staticSize;
                $idxList[] = $realIndex;
            }
        }
        return $idxList;
    }

    public function insert(string $name, string $value) : void
    {
        ArrayUtil::insert(new KeyVal($name, $value), $this->idxMap, 0);
        $this->addCount++;
        $this->addToNameMap($name, $this->addCount);
    }

    public function setSize(int $size) : void
    {
    }

    private function put(int $idx, string $name, ?string $value) : void
    {
        if($idx != count($this->idxMap) + 1)
            throw new \InvalidArgumentException();
        $this->idxMap[] = new KeyVal($name, $value);
        $this->addToNameMap($name, $idx);
    }

    private function addToNameMap(string $name, int $idx) : void
    {
        if(!array_key_exists($name, $this->nameMap)) {
            $idxList = [];
            $this->nameMap[$name] = $idxList;
        }
        $idxList = &$this->nameMap[$name];
        $idxList[] = $idx;
    }

    public static function createDynamicTable() : HeaderTable
    {
        return new HeaderTable();
    }

    public static function initialize() : void
    {
        self::$staticTable = new HeaderTable();
        self::$staticTable->put(1, self::PSEUDO_HEADER_AUTHORITY, "");
        self::$staticTable->put(2, self::PSEUDO_HEADER_METHOD, "GET");
        self::$staticTable->put(3, self::PSEUDO_HEADER_METHOD, "POST");
        self::$staticTable->put(4, self::PSEUDO_HEADER_PATH, "/");
        self::$staticTable->put(5, self::PSEUDO_HEADER_PATH, "/index.html");
        self::$staticTable->put(6, self::PSEUDO_HEADER_SCHEME, "http");
        self::$staticTable->put(7, self::PSEUDO_HEADER_SCHEME, "https");
        self::$staticTable->put(8, self::PSEUDO_HEADER_STATUS, "200");
        self::$staticTable->put(9, self::PSEUDO_HEADER_STATUS, "204");
        self::$staticTable->put(10, self::PSEUDO_HEADER_STATUS, "206");
        self::$staticTable->put(11, self::PSEUDO_HEADER_STATUS, "304");
        self::$staticTable->put(12, self::PSEUDO_HEADER_STATUS, "400");
        self::$staticTable->put(13, self::PSEUDO_HEADER_STATUS, "404");
        self::$staticTable->put(14, self::PSEUDO_HEADER_STATUS, "500");
        self::$staticTable->put(15, "accept-charset", "");
        self::$staticTable->put(16, "accept-encoding", "gzip, deflate");
        self::$staticTable->put(17, "accept-language", "");
        self::$staticTable->put(18, "accept-ranges", "");
        self::$staticTable->put(19, "accept", "");
        self::$staticTable->put(20, "access-control-allow-origin", "");
        self::$staticTable->put(21, "age", "");
        self::$staticTable->put(22, "allow", "");
        self::$staticTable->put(23, "authorization", "");
        self::$staticTable->put(24, "cache-control", "");
        self::$staticTable->put(25, "content-disposition", "");
        self::$staticTable->put(26, "content-encoding", "");
        self::$staticTable->put(27, "content-language", "");
        self::$staticTable->put(28, "content-length", "");
        self::$staticTable->put(29, "content-location", "");
        self::$staticTable->put(30, "content-range", "");
        self::$staticTable->put(31, "content-type", "");
        self::$staticTable->put(32, "cookie", "");
        self::$staticTable->put(33, "date", "");
        self::$staticTable->put(34, "etag", "");
        self::$staticTable->put(35, "expect", "");
        self::$staticTable->put(36, "expires", "");
        self::$staticTable->put(37, "from", "");
        self::$staticTable->put(38, "host", "");
        self::$staticTable->put(39, "if-match", "");
        self::$staticTable->put(40, "if-modified-since", "");
        self::$staticTable->put(41, "if-none-match", "");
        self::$staticTable->put(42, "if-range", "");
        self::$staticTable->put(43, "if-unmodified-since", "");
        self::$staticTable->put(44, "last-modified", "");
        self::$staticTable->put(45, "link", "");
        self::$staticTable->put(46, "location", "");
        self::$staticTable->put(47, "max-forwards", "");
        self::$staticTable->put(48, "proxy-authenticate", "");
        self::$staticTable->put(49, "proxy-authorization", "");
        self::$staticTable->put(50, "range", "");
        self::$staticTable->put(51, "referer", "");
        self::$staticTable->put(52, "refresh", "");
        self::$staticTable->put(53, "retry-after", "");
        self::$staticTable->put(54, "server", "");
        self::$staticTable->put(55, "set-cookie", "");
        self::$staticTable->put(56, "strict-transport-security", "");
        self::$staticTable->put(57, "transfer-encoding", "");
        self::$staticTable->put(58, "user-agent", "");
        self::$staticTable->put(59, "vary", "");
        self::$staticTable->put(60, "via", "");
        self::$staticTable->put(61, "www-authenticate", "");

        self::$staticSize = count(self::$staticTable->idxMap);

    }
}

HeaderTable::initialize();