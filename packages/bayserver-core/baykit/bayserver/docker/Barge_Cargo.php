<?php

namespace baykit\bayserver\docker;

use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\util\Headers;

/**
 * "Cargo" is a metaphor for the cached data carried by a Barge.
 *
Cargo. PHP cannot express nested
 * interfaces directly, so it lives in a separate file named with an
 * underscore (Barge_Cargo) per the same convention used for other inner
 * types in this code base (e.g. CmdSettings_Item).
 */
interface Barge_Cargo
{
    public function path() : string;

    public function headers() : Headers;

    public function content() : string;

    public function length() : int;

    public function onBarge() : bool;

    public function exceeded() : bool;

    public function saveHeaders(Headers $headers) : void;

    public function saveContent(string $bytes, int $offset, int $len) : void;

    public function endSave() : void;

    public function releaseRudder(Rudder $rudder) : void;
}
