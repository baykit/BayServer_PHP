<?php

namespace baykit\bayserver\util;

class URLEncoder {
    /**
     * Encode tilde char only
     */
    public static function encodeTilde(string $url) : string
    {
        $b = "";
        for ($i = 0; $i < strlen($url); $i++) {
            $c = $url[$i];
            if ($c == '~')
                $b .= "%7E";
            else
                $b .= $c;
        }

        return $b;
    }
}
