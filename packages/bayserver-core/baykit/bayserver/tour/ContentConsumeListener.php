<?php
namespace baykit\bayserver\tour;


abstract class ContentConsumeListener {

    public static $devNull;

    public abstract function contentConsumed(int $len, bool $resume) : void;

};

ContentConsumeListener::$devNull = new class extends ContentConsumeListener {
    public function  contentConsumed(int $len, bool $resume) : void
    {
    }
};
