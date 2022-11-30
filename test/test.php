<?php


$bhome = dirname(__FILE__) . "/..";

$sla = "/";
$path = get_include_path();
$path = $path . PATH_SEPARATOR . $bhome . $sla . "core";

$dockers = "$bhome" . $sla . "docker";
$dirs = glob("$dockers/*", GLOB_ONLYDIR);
foreach ($dirs as $dir) {
    $path = $path . PATH_SEPARATOR . $dir;
}

ini_set('include_path', $path);

require 'baykit/bayserver/BayServer.php';

\baykit\bayserver\BayServer::main($argv);
