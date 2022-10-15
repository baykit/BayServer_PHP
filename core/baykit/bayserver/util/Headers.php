<?php
namespace baykit\bayserver\util;

class Headers
{
    /**
     * Known header names
     */
    const HEADER_SEPARATOR = ": ";

    const CONTENT_TYPE = "content-type";
    const CONTENT_LENGTH = "content-length";
    const CONTENT_ENCODING = "content-encoding";
    const HDR_TRANSFER_ENCODING = "Transfer-Encoding";
    const CONNECTION = "Connection";
    const AUTHORIZATION = "Authorization";
    const WWW_AUTHENTICATE = "WWW-Authenticate";
    const STATUS = "Status";
    const LOCATION = "Location";
    const HOST = "Host";
    const COOKIE = "Cookie";
    const USER_AGENT = "User-Agent";
    const ACCEPT = "Accept";
    const ACCEPT_LANGUAGE = "Accept-Language";
    const ACCEPT_ENCODING = "Accept-Encoding";
    const UPGRADE_INSECURE_REQUESTS = "Upgrade-Insecure-Requests";
    const SERVER = "Server";
    const X_FORWARDED_HOST = "X-Forwarded-Host";
    const X_FORWARDED_FOR = "X-Forwarded-For";
    const X_FORWARDED_PROTO = "X-Forwarded-Proto";
    const X_FORWARDED_PORT = "X-Forwarded-Port";

    const CONNECTION_CLOSE = 1;
    const CONNECTION_KEEP_ALIVE = 2;
    const CONNECTION_UPGRADE = 3;
    const CONNECTION_UNKOWN = 4;

    /** Status */
    public $status = HttpStatus::OK;

    /** Header hash */
    public $headers = [];

    public function __toString()
    {
        return "Headers(s=" . $this->status . " h=" . strval($this->headers);
    }

    public function copyTo(Headers $dst): void
    {
        $dst->status = $this->status;
        foreach ($this->headers as $name => $value) {
            $values = $this->headers[$name]; // copy
            $dst->headers[$name] = $values;
        }
    }

    /**
     * Get the header value as string
     */
    public function get(string $name): ?string
    {
        $name = strtolower($name);
        if(!array_key_exists($name, $this->headers))
            return null;

        $values = $this->headers[$name];
        return $values[0];
    }

    /**
     * Get the header value as int
     */
    public function getInt(string $name): int
    {
        $val = $this->get($name);
        if ($val === null)
            return -1;
        else
            return intval($val);
    }

    /**
     * Update the header value by string
     */
    public function set(string $name, string $value): void
    {
        $name = strtolower($name);
        $values = &$this->headers[$name];
        if ($values === null) {
            $values = [];
            $this->headers[$name] = $values;
        }
        $values = [$value];
    }

    /**
     * Update the header value by int
     */
    public function setInt(string $name, int $value): void
    {
        $this->set($name, strval($value));
    }

    /**
     * Add a header value by string
     */
    public function add(string $name, string $value): void
    {
        $name = strtolower($name);
        $values = &$this->headers[$name];
        if ($values === null) {
            $values = [];
            $this->headers[$name] = &$values;
        }
        $values[] = $value;
    }

    /**
     * Add a header value by int
     */
    public function addInt(string $name, int $value): void
    {
        $this->add($name, strval($value));
    }


    /**
     * Get all the header name
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->headers as $name => $value) {
            $names[] = $name;
        }
        return $names;
    }

    /**
     * Get all the header values of specified header name
     */
    public function values(string $name): array
    {
        $values = $this->headers[strtolower($name)];
        if ($values === null)
            return [];
        else
            return $values;
    }

    /**
     * Check the existence of header
     */
    public function contains(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->headers);
    }

    public function remove(string $name): void
    {
        unset($this->headers[$name]);
    }

    ////////////////////////////////////////////////////////////////////////////
    // Utility methods
    ////////////////////////////////////////////////////////////////////////////

    public function contentType(): ?string
    {
        return $this->get(Headers::CONTENT_TYPE);
    }

    public function setContentType(string $type): void
    {
        $this->set(Headers::CONTENT_TYPE, $type);
    }

    public function contentLength(): int
    {
        $length = $this->get(Headers::CONTENT_LENGTH);
        if (StringUtil::isEmpty($length))
            return -1;
        else
            return intval($length);
    }

    public function setContentLength(int $length): void
    {
        $this->set(Headers::CONTENT_LENGTH, strval($length));
    }

    public function getConnection(): int
    {
        $con = $this->get(Headers::CONNECTION);
        if ($con !== null)
            $con = strtolower($con);

        switch ($con) {
            case "close":
                return Headers::CONNECTION_CLOSE;
            case "keep-alive":
                return Headers::CONNECTION_KEEP_ALIVE;
            case "upgrade":
                return Headers::CONNECTION_UPGRADE;
            default:
                return Headers::CONNECTION_UNKOWN;
        }
    }

    public function clear() : void
    {
        $this->headers = [];
        $this->status = HttpStatus::OK;
    }
}

