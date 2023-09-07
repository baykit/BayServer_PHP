<?php
require "vendor/autoload.php";

$sla=DIRECTORY_SEPARATOR;
$bhome = dirname(__FILE__) . $sla  . "..";
putenv("BSERV_HOME={$bhome}");

$path = get_include_path();
$path = $path . PATH_SEPARATOR . $bhome . $sla . "lib" . $sla . "core";

$dockers = "$bhome" . $sla . "lib" . $sla . "docker";
$dirs = glob("$dockers/*", GLOB_ONLYDIR);
foreach ($dirs as $dir) {
    $path = $path . PATH_SEPARATOR . $dir;
}

ini_set('include_path', $path);

use baykit\bayserver\BayServer;

BayServer::main($argv);

