<?php
namespace baykit\bayserver\util;

use baykit\bayserver\docker\City;

class Cities
{
    # Default city docke
    public $anyCity = null;

    # City docker
    public $cities = [];

    public function add(City $c) : void
    {
        if($c->name() == "*")
            $this->anyCity = $c;
        else
            $this->cities[] = $c;
    }

    public function findCity(string $hostName) : ?City
    {
        // Check exact match
        foreach ($this->cities as $c) {
            if ($c->name() == $hostName)
                return $c;
        }
        return $this->anyCity;
    }

    public function cities() : array
    {
        $ret = $this->cities(); // copy
        if($this->anyCity != null)
            $ret[] = $this->anyCity;
        return $ret;
    }
}

