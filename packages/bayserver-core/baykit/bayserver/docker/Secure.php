<?php
namespace baykit\bayserver\docker;


use baykit\bayserver\common\Transporter;
use baykit\bayserver\ship\Ship;

interface Secure extends Docker
{
    public function setAppProtocols(string $protocols) : void;

    public function reloadCert() : void;

    public function newTransporter(int $agtId, Ship $sip, int $bufsiz) : Transporter;
}