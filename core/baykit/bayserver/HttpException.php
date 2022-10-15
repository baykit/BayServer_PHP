<?php

namespace baykit\bayserver;

use baykit\bayserver\util\HttpStatus;

class HttpException extends BayException
{
    public $status; // HTTP status
    public $location; // for 302

    public function __construct(int $status, string $fmt=null, ...$args) {
        parent::__construct($fmt, ...$args);
        $this->status = $status;
        if ($status < 300 || $status >= 600)
            throw new \Exception("Illegal Http error status code: %d", $status);

        $this->message = "HTTP " . $this->status . " " . HttpStatus::description($this->status) . ": "
            . (parent::getMessage() === null ? "" : parent::getMessage());
    }

    public static function movedTemp(string $location) : HttpException
    {
        $e = new HttpException(HttpStatus::MOVED_TEMPORARILY, $location);
        $e->location = $location;
        return $e;
    }
}