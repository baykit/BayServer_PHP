<?php
namespace baykit\bayserver\util;

class KeyVal
{
    public $name;
    public $value;

    public function __construct(string $name, ?string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }
}