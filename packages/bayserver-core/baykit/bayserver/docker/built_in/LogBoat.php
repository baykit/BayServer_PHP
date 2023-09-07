<?php

namespace baykit\bayserver\docker\built_in;

use baykit\bayserver\util\Reusable;
use baykit\bayserver\watercraft\Boat;

class LogBoat extends Boat implements Reusable {

    private $fileName;
    private $postman;

    public function __toString()
    {
        return "lboat#" . $this->boartId . "/" . $this->objectId . " file=" . $this->fileName;
    }


    ////////////////////////////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        $this->fileName = null;
        $this->postman = null;
    }

    ////////////////////////////////////////////////////////////////////
    // Implements DataListener
    ////////////////////////////////////////////////////////////////////
    public function notifyClose(): void
    {
    }

    ////////////////////////////////////////////////////////////////////
    // Custom methods
    ////////////////////////////////////////////////////////////////////
    public function initBoat(string $fileName, $postman) : void
    {
        parent::init();
        $this->fileName = $fileName;
        $this->postman = $postman;
    }

    public function log(?string $data) : void
    {
        if($data === null)
            $data = "";
        $data .= PHP_EOL;
        $this->postman->post($data, null, $this->fileName, null);
    }
}