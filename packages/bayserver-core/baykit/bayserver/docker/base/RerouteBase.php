<?php
namespace baykit\bayserver\docker\base;

use baykit\bayserver\ConfigException;

use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\docker\Reroute;


abstract class RerouteBase extends DockerBase implements Reroute
{

    ///////////////////////////////////////////////////////////////////////
    // Implements Docker
    ///////////////////////////////////////////////////////////////////////

    public function init(BcfElement $elm, ?Docker $parent) : void
    {
        $name = $elm->arg;
        if($name != "*")
            throw new ConfigException($elm->fileName, $elm->lineNo, "Invalid reroute name: " . $name);

        parent::init($elm, $parent);
    }


    ///////////////////////////////////////////////////////////////////////
    // Other methods
    ///////////////////////////////////////////////////////////////////////

    protected function match(string $url) : bool
    {
        return true;
    }

}