<?php

namespace baykit\bayserver\bcf;

class BcfDocument
{
    public $contentList = [];

    public function printDocument()
    {
        $this->printContentList($this->contentList, 0);
    }

    public function printContentList($list, $indent)
    {
        foreach ($list as $o) {
            $this->print_indent($indent);
            if ($o instanceof BcfElement) {
                echo("Element({$o->name}, {$o->arg})\n");
                $this->printContentList($o->contentList, $indent + 1);
                $this->print_indent($indent);
                echo("\n");
            } else {
                echo("KeyVal({$o->key}, {$o->value})");
                echo("\n");
            }
        }
    }

    public function print_indent($indent)
    {
        for ($i = 1; $i <= $indent; $i++) {
            print(" ");
        }
    }
}

