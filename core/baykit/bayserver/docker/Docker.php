<?php
namespace baykit\bayserver\docker;

use baykit\bayserver\bcf\BcfElement;

interface Docker {

    public function init(BcfElement $elm, ?Docker $parent) : void;
}
