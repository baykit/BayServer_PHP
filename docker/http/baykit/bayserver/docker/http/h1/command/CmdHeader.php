<?php

namespace baykit\bayserver\docker\http\h1\command;

use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\docker\http\h1\H1Command;
use baykit\bayserver\docker\http\h1\H1Type;
use baykit\bayserver\protocol\CommandHandler;
use baykit\bayserver\protocol\Packet;
use baykit\bayserver\protocol\PacketPartAccessor;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\Symbol;
use baykit\bayserver\util\CharUtil;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\StringUtil;
use const Grpc\CHANNEL_READY;


/**
 * Header format
 *
 *
 *        generic-message = start-line
 *                           *(message-header CRLF)
 *                           CRLF
 *                           [ message-body ]
 *        start-line      = Request-Line | Status-Line
 *
 *
 *        message-header = field-name ":" [ field-value ]
 *        field-name     = token
 *        field-value    = *( field-content | LWS )
 *        field-content  = <the OCTETs making up the field-value
 *                         and consisting of either *TEXT or combinations
 *                         of token, separators, and quoted-string>
 */
class CmdHeader extends H1Command
{
    const STATE_READ_FIRST_LINE = 1;
    const STATE_READ_MESSAGE_HEADERS = 2;

    public $headers = [];
    public $isReqHeader;
    public $method;
    public $uri;
    public $version;
    public $status;

    public function __construct(bool $isReqHeader)
    {
        parent::__construct(H1Type::HEADER);
        $this->headers = [];
        $this->isReqHeader = $isReqHeader;
    }

    public function __toString()
    {
        return "CommandHeader[H1]";
    }

    public static function newReqHeader($method, $uri, $version) : CmdHeader
    {
        $h = new CmdHeader(true);
        $h->method = $method;
        $h->uri = $uri;
        $h->version = $version;
        return $h;
    }

    public static function newResHeader($headers, $version) : CmdHeader
    {
        $h = new CmdHeader(false);
        $h->version = $version;
        $h->status = $headers->status;
        foreach ($headers->names() as $name) {
            foreach ($headers->values($name) as $value) {
                $h->addHeader($name, $value);
            }
        }
        return $h;
    }

    public function addHeader(string $name, ?string $value) : void
    {
        if($value == null) {
            BayLog::warn("Header value is null: %s", $name);
        }
        else {
            $this->headers[] = [$name, $value];
        }
    }

    public function setHeader(string $name, ?string $value) : void
    {
        if($value == null) {
            BayLog::warn("Header value is null: %s", $name);
            return;
        }
        foreach ($this->headers as $nv) {
            if (strtolower($nv[0]) == strtolower($name)) {
                $nv[1] = $value;
                return;
            }
        }
        $this->headers[] = [$name, $value];
    }

    public function unpack(Packet $pkt): void
    {
        $acc = $pkt->newDataAccessor();
        $data_len = $pkt->dataLen();
        $state = CmdHeader::STATE_READ_FIRST_LINE;

        $lineStartPos = 0;
        $lineLen = 0;

        for ($pos = 0; $pos < $data_len; $pos++) {
            $b = $acc->getByte();
            $breakLoop = false;
            switch ($b) {
                case CharUtil::CR_BYTE:
                    break;

                case CharUtil::LF_BYTE:
                    if ($lineLen == 0) {
                        $breakLoop = true;
                        break;
                    }

                    if ($state == CmdHeader::STATE_READ_FIRST_LINE) {
                        if ($this->isReqHeader)
                            $this->unpackRequestLine($pkt->buf, $lineStartPos, $lineLen);
                        else
                            $this->unpackStatusLine($pkt->buf, $lineStartPos, $lineLen);

                        $state = CmdHeader::STATE_READ_MESSAGE_HEADERS;
                    } else
                        $this->unpackMessageHeader($pkt->buf, $lineStartPos, $lineLen);

                    $lineLen = 0;
                    $lineStartPos = $pos + 1;
                    break;

                default:
                    $lineLen += 1;
            }

            if($breakLoop)
                break;
        }

        if ($state == CmdHeader::STATE_READ_FIRST_LINE) {
            throw new \Exception("Invalid HTTP header format");
        }
    }

    public function pack(Packet $pkt): void
    {
        $acc = $pkt->newDataAccessor();
        if ($this->isReqHeader)
            $this->packRequestLine($acc);
        else
            $this->packStatusLine($acc);

        foreach ($this->headers as $nv)
            $this->packMessageHeader($acc, $nv[0], $nv[1]);

        $this->packEndHeader($acc);
    }

    public function handle(CommandHandler $handler): int
    {
        return $handler->handleHeader($this);
    }

    /******************************************************************/
    /* Private methods                                                */
    /******************************************************************/

    private function unpackRequestLine(string $buf, int $start, int $len) : void
    {
        $line = substr($buf, $start, $start + $len);
        $items = explode(" ", $line);
        if (count($items) != 3)
            throw new ProtocolException(BayMessage::get(Symbol::HTP_INVALID_FIRST_LINE, $line));

        $this->method = $items[0];
        $this->uri = $items[1];
        $this->version = $items[2];
    }

    private function unpackStatusLine(string $buf, int $start, int $len) : void
    {
        $line = substr($buf, $start, $len);
        $parts = explode(" ", $line);

        if(count($parts) < 2)
            throw new IOException(
                BayMessage::get(Symbol::HTP_INVALID_FIRST_LINE, $line));

        try {
            $version = $parts[0];
            $status = $parts[1];
            $this->status = intval($status);

        }
        catch (\Exception $e) {
            throw new IOException(
                BayMessage::get(Symbol::HTP_INVALID_FIRST_LINE, $line));
        }
    }

    private function unpackMessageHeader(string $bytes, int $start, int $len): void
    {
        $buf = "";
        $read_name = true;
        $name = null;
        $skipping = true;

        for ($i = 0; $i < $len; $i++) {
            $b = $bytes[$start + $i];
            if ($skipping && $b == " ")
                continue;
            elseif ($read_name && $b == ":") {
                # header name completed
                $name = $buf;
                $buf = "";
                $skipping = true;
                $read_name = false;
            } else {
                if ($read_name) {
                    # make the case of header name be lower force
                    $buf .= strtolower($b);
                } else {
                    # header value
                    $buf .= $b;
                }

                $skipping = false;
            }
        }

        if ($name === null)
            throw new ProtocolException(BayMessage::get(Symbol::HTP_INVALID_HEADER_FORMAT, ""));

        $value = $buf;

        $this->addHeader($name, $value);
    }


    private function packRequestLine(PacketPartAccessor $acc)
    {
        $acc->putString($this->method);
        $acc->putByte(ord(" "));
        $acc->putString($this->uri);
        $acc->putByte(ord(" "));
        $acc->putString($this->version);
        $acc->putBytes(CharUtil::CRLF);
    }

    private function packStatusLine(PacketPartAccessor $acc) : void
    {
        $desc = HttpStatus::description($this->status);

        if ($this->version !== null && StringUtil::eqIgnorecase($this->version, "HTTP/1.1"))
            $acc->putBytes("HTTP/1.1");
        else
            $acc->putBytes("HTTP/1.0");

        // status
        $acc->putBytes(" ");
        $acc->putString(strval($this->status));
        $acc->putBytes(" ");
        $acc->putString($desc);
        $acc->putBytes(CharUtil::CRLF);
    }

    private function packMessageHeader(PacketPartAccessor $acc, string $name, string $value) : void
    {
        $acc->putString($name);
        $acc->putByte(ord(":"));
        $acc->putString($value);
        $acc->putBytes(CharUtil::CRLF);
    }

    private function packEndHeader(PacketPartAccessor $acc) : void
    {
        $acc->putBytes(CharUtil::CRLF);
    }
}