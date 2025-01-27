<?php

namespace baykit\bayserver\docker\ajp\command;

use baykit\bayserver\BayLog;
use baykit\bayserver\docker\ajp\AjpCommand;
use baykit\bayserver\docker\ajp\AjpPacket;
use baykit\bayserver\docker\ajp\AjpType;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\StringUtil;


/**
 * Send headers format
 *
 * AJP13_SEND_HEADERS :=
 *   prefix_code       4
 *   http_status_code  (integer)
 *   http_status_msg   (string)
 *   num_headers       (integer)
 *   response_headers *(res_header_name header_value)
 *
 * res_header_name :=
 *     sc_res_header_name | (string)   [see below for how this is parsed]
 *
 * sc_res_header_name := 0xA0 (byte)
 *
 * header_value := (string)
 */
class CmdSendHeaders extends AjpCommand
{
    public static $wellKnownHeaders = [
        "content-type" => 0xA001,
        "content-language" => 0xA002,
        "content-length" => 0xA003,
        "date" => 0xA004,
        "last-modified" => 0xA005,
        "location" => 0xA006,
        "set-cookie" => 0xA007,
        "set-cookie2" => 0xA008,
        "servlet-engine" => 0xA009,
        "status" => 0xA00A,
        "www-authenticate" => 0xA00B,
    ];

    static function getWellKnownHeaderName(string $code) : ?string
    {
        foreach(self::$wellKnownHeaders as $name => $cd) {
            if($cd == $code)
                return $name;
        }
        return null;
    }

    public $headers = [];
    public $status;
    public $desc;

    public function __construct()
    {
        parent::__construct(AjpType::SEND_HEADERS, false);
        $this->status = HttpStatus::OK;
        $this->desc = null;
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newAjpDataAccessor();
        $acc->putByte($this->type);
        $acc->putShort($this->status);
        $acc->putString(HttpStatus::description($this->status));

        $count = 0;
        foreach($this->headers as $name => $values) {
            $count += count($values);
        }

        $acc->putShort($count);
        foreach($this->headers as $name => $values) {
            $code = null;
            if(array_key_exists($name, self::$wellKnownHeaders))
                $code = self::$wellKnownHeaders[strtolower($name)];
            foreach ($values as $value) {
                if ($code != null) {
                    $acc->putShort($code);
                } else {
                    $acc->putString($name);
                }
                $acc->putString($value);
            }
        }

        // must be called from last line
        parent::pack($pkt);
    }

    public function unpack(Packet $pkt): void
    {
        $acc = $pkt->newAjpDataAccessor();
        $prefixCode = $acc->getByte();
        if($prefixCode != AjpType::SEND_HEADERS)
            throw new ProtocolException("Expected SEND_HEADERS");
        $this->setStatus($acc->getShort());
        $this->setDesc($acc->getString());
        $count = $acc->getShort();
        for($i = 0; $i < $count; $i++) {
            $code = $acc->getShort();
            $name = self::getWellKnownHeaderName($code);
            if($name == null) {
                // code is length
                $name = $acc->getStringByLen($code);
            }
            $value = $acc->getString();
            $this->addHeader($name, $value);
        }
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleSendHeaders($this);
    }

    public function setStatus(int $status) : void
    {
        $this->status = $status;
    }

    public function setDesc(string $desc) : void
    {
        $this->desc = $desc;
    }

    public function getHeader(string $name) : ?string
    {
        $name = strtolower($name);
        $values = array_key_exists($name, $this->headers) ? $this->headers[$name] : null;
        if($values == null || count($values) == 0)
            return null;
        else
            return $values[0];
    }

    public function addHeader(string $name, string $value) : void
    {
        $name = strtolower($name);
        $values = array_key_exists($name, $this->headers) ? $this->headers[$name] : null;
        if($values == null) {
            $this->headers[$name] = [$value];
        }
        else {
            $this->headers[$name][] = $value;
        }
    }

    public function getContentLength() : int
    {
        $len = $this->getHeader("content-length");
        if(StringUtil::isEmpty($len))
            return -1;
        else {
            try {
                return intval($len);
            }
            catch (\Error $e) {
                BayLog::error_e($e);
                return -1;
            }
        }
    }
}