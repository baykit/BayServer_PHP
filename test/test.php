<?php

require 'vendor/autoload.php';

$bhome = dirname(__FILE__) . "/..";

$sla = "/";
$path = get_include_path();

$dockers = "$bhome" . $sla . "packages";
$dirs = glob("$dockers/*", GLOB_ONLYDIR);
foreach ($dirs as $dir) {
    $path = $path . PATH_SEPARATOR . $dir;
}

print  $path . PHP_EOL;
ini_set('include_path', $path);

$blib = "../../packages/bayserver";
putenv("BSERV_LIB={$blib}");


require 'baykit/bayserver/BayServer.php';

\baykit\bayserver\BayServer::main($argv);
