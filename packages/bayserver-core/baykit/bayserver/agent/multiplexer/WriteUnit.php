<?php

namespace baykit\bayserver\agent\multiplexer;

class WriteUnit {
    public string $buf;
    public ?string $adr;
    public $tag;
    public $callback;

    public function __construct(string $buf, ?string $adr, $tag, ?callable $callback)
    {
        $this->buf = $buf;
        $this->adr = $adr;
        $this->tag = $tag;
        $this->callback = $callback;
    }

    public function done() : void
    {
        if($this->callback !== null)
            ($this->callback)();
    }
}