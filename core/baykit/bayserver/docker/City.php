<?php
namespace baykit\bayserver\docker;


use baykit\bayserver\tour\Tour;

interface City extends Docker
{
    /**
     * City name (host name)
     * @return
     */
    public function name() : string;

    /**
     * All clubs (not included in town) in this city
     * @return
     */
    public function clubs() : array;


    /**
     * All towns in this city
     * @return
     */
    public function towns() : array;

    /**
     * Enter city
     * @param tour
     */
    public function enter(Tour $tur) : void;

    /**
     * Get trouble docker
     * @return
     */
    //public function getTrouble() : trouble;

    /**
     * Logging
     * @param tour
     */
    public function log(Tour $tur) : void;
}