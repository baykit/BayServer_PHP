<?php
namespace baykit\bayserver\docker;

use baykit\bayserver\agent\transporter\Transporter;

interface Secure extends Docker
{
    public function setAppProtocols(string $protocols) : void;

    public function reloadCert() : void;

    public function createTransporter(int $bufsize) : Transporter;
}