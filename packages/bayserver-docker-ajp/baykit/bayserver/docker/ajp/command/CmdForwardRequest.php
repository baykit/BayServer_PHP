<?php

namespace baykit\bayserver\docker\ajp\command;

use baykit\bayserver\docker\ajp\AjpCommand;
use baykit\bayserver\docker\ajp\AjpPacketPartAccessor;
use baykit\bayserver\docker\ajp\AjpType;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\StringUtil;


/**
 * AJP protocol
 *    https://tomcat.apache.org/connectors-doc/ajp/ajpv13a.html
 *
 * AJP13_FORWARD_REQUEST :=
 *     prefix_code      (byte) 0x02 = JK_AJP13_FORWARD_REQUEST
 *     method           (byte)
 *     protocol         (string)
 *     req_uri          (string)
 *     remote_addr      (string)
 *     remote_host      (string)
 *     server_name      (string)
 *     server_port      (integer)
 *     is_ssl           (boolean)
 *     num_headers      (integer)
 *     request_headers *(req_header_name req_header_value)
 *     attributes      *(attribut_name attribute_value)
 *     request_terminator (byte) OxFF
 */
class CmdForwardRequest extends AjpCommand
{
    public static $methods = [
        1 => "OPTIONS",
        2 => "GET",
        3 => "HEAD",
        4 => "POST",
        5 => "PUT",
        6 => "DELETE",
        7 => "TRACE",
        8 => "PROPFIND",
        9 => "PROPPATCH",
        10 => "MKCOL",
        11 => "COPY",
        12 => "MOVE",
        13 => "LOCK",
        14 => "UNLOCK",
        15 => "ACL",
        16 => "REPORT",
        17 => "VERSION_CONTROL",
        18 => "CHECKIN",
        19 => "CHECKOUT",
        20 => "UNCHECKOUT",
        21 => "SEARCH",
        22 => "MKWORKSPACE",
        23 => "UPDATE",
        24 => "LABEL",
        25 => "MERGE",
        26 => "BASELINE_CONTROL",
        27 => "MKACTIVITY",
    ];

    static function getMethodCode(string $method) : int
    {
        foreach(self::$methods as $code => $desc) {
            if(StringUtil::eqIgnorecase($desc, $method))
                return $code;
        }
        return -1;
    }

    public static $wellKnownHeaders = [
        0xA001 => "Accept",
        0xA002 => "Accept-Charset",
        0xA003 => "Accept-Encoding",
        0xA004 => "Accept-Language",
        0xA005 => "Authorization",
        0xA006 => "Connection",
        0xA007 => "Content-Type",
        0xA008 => "Content-Length",
        0xA009 => "Cookie",
        0xA00A => "Cookie2",
        0xA00B => "Host",
        0xA00C => "Pragma",
        0xA00D => "Referer",
        0xA00E => "User-Agent",
    ];

    static function getWellKnownHeaderCode(string $name) : int
    {
        foreach(self::$wellKnownHeaders as $code => $desc) {
            if(StringUtil::eqIgnorecase($desc, $name))
                return $code;
        }
        return -1;
    }

    public static $attributeNames = [
        0x01 => "?context",
        0x02 => "?servlet_path",
        0x03 => "?remote_user",
        0x04 => "?auth_type",
        0x05 => "?query_string",
        0x06 => "?route",
        0x07 => "?ssl_cert",
        0x08 => "?ssl_cipher",
        0x09 => "?ssl_session",
        0x0A => "?req_attribute",
        0x0B => "?ssl_key_size",
        0x0C => "?secret",
        0x0D => "?stored_method",
    ];

    static function getAttributeCode(string $str) : int
    {
        foreach(self::$attributeNames as $code => $desc) {
            if(StringUtil::eqIgnorecase($desc, $str))
                return $code;
        }
        return -1;
    }

    public ?string $method;
    public ?string $protocol;
    public ?string $reqUri;
    public ?string $remoteAddr;
    public ?string $remoteHost;
    public ?string $serverName;
    public int $serverPort;
    public bool $isSsl;
    public Headers $headers;
    public $attributes = [];

    public function __construct()
    {
        parent::__construct(AjpType::FORWARD_REQUEST, false);
        $this->headers = new Headers();
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newAjpDataAccessor();
        $acc->putByte($this->type); // prefix code
        $acc->putByte(self::getMethodCode($this->method));
        $acc->putString($this->protocol);
        $acc->putString($this->reqUri);
        $acc->putString($this->remoteAddr);
        $acc->putString($this->remoteHost);
        $acc->putString($this->serverName);
        $acc->putShort($this->serverPort);
        $acc->putByte($this->isSsl ? 1 : 0);
        $this->writeRequestHeaders($acc);
        $this->writeAttributes($acc);

        // must be called from last line
        parent::pack($pkt);
    }

    public function unpack(Packet $pkt): void
    {
        parent::unpack($pkt);

        $acc = $pkt->newAjpDataAccessor();
        $acc->getByte(); // prefix code
        $this->method = self::$methods[$acc->getByte()];
        $this->protocol = $acc->getString();
        $this->reqUri = $acc->getString();
        $this->remoteAddr = $acc->getString();
        $this->remoteHost = $acc->getString();
        $this->serverName = $acc->getString();
        $this->serverPort = $acc->getShort();
        $this->isSsl = $acc->getByte() == 1;
        //BayLog.debug("ForwardRequest: uri=" + reqUri);

        $this->readRequestHeaders($acc);
        $this->readAttributes($acc);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleForwardRequest($this);
    }

    private function readRequestHeaders(AjpPacketPartAccessor $acc) : void
    {
        $count = $acc->getShort();
        for ($i = 0; $i < $count; $i++) {
            $code = $acc->getShort();
            if ($code >= 0xA000) {
                if(!array_key_exists($code, self::$wellKnownHeaders))
                    throw new ProtocolException("Invalid header");
                $name = self::$wellKnownHeaders[$code];
            }
            else {
                $name = $acc->getStringByLen($code);
            }
            $value = $acc->getString();
            $this->headers->add($name, $value);
            //BayLog.debug("ajp: ForwardRequest header:" + name + ":" + value);
        }
    }

    private function readAttributes(AjpPacketPartAccessor $acc) : void
    {
        while (true) {
            $code = $acc->getByte();
            //BayLog.debug("ajp: ForwardRequest readAttributes: code=" + Integer.toHexString(code));
            if ($code == 0xFF) {
                break;
            }
            else if ($code == 0x0A) {
                $name = $acc->getString();
            }
            else {
                $name = self::$attributeNames[$code];
            }
            if ($name == null)
                throw new ProtocolException("Invalid attribute: code=" . $code);

            if ($code == 0x0B) { // "?ssl_key_size"
                $value = $acc->getShort();
                $this->attributes[$name] = strval($value);
            }
            else {
                $value = $acc->getString();
                $this->attributes[$name] = $value;
            }
        }
    }

    private function writeRequestHeaders(AjpPacketPartAccessor $acc) : void
    {
        $hlist = [];
        foreach($this->headers->names() as $name) {
            foreach($this->headers->values($name) as $value) {
                $hlist[] = [$name, $value];
            }
        };
        $acc->putShort(count($hlist));
        foreach ($hlist as $hdr) {
            $code = self::getWellKnownHeaderCode($hdr[0]);
            if($code != -1) {
                $acc->putShort($code);
            }
            else {
                $acc->putString($hdr[0]);
            }
            $acc->putString($hdr[1]);
            //BayServer.debug("ForwardRequest header:" + name + ":" + value);
        }
    }


    private function writeAttributes(AjpPacketPartAccessor  $acc) : void
    {
        foreach($this->attributes as $name => $value) {
            $code = $this->getAttributeCode($name);
            if($code != -1) {
                $acc->putByte($code);
            }
            else {
                $acc->putString($name);
            }
            $acc->putString($value);
        }
        $acc->putByte(0xFF); // terminator code
    }

}