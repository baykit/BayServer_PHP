<?php

namespace baykit\bayserver\docker\http\h2\huffman;

class HNode
{
    public $value = -1;   //  if vlaue > 0 leaf node else inter node
    public $one; // HNode
    public $zero; // HNode
}