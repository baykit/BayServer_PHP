<?php

namespace baykit\bayserver\docker\built_in;


use baykit\bayserver\tour\Tour;

abstract class LogItem {


    /**
     * initialize
     */
    public function init(?string $param) : void
    {
    }

    /**
     * Print log
     */
    abstract function getItem(Tour $tour) : ?string;

}