<?php
namespace baykit\bayserver\util;


class MD5Password
{
    public static function encode(string $password) : string
    {
        return md5($password);
	}
}

