<?php
namespace baykit\bayserver\util;

interface DataConsumeListener
{
    public function dataConsumed() : void;
}
