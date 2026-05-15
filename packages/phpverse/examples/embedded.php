<?php

declare(strict_types=1);

/**
 * Library-mode demo: spawn one worker via ForkedWorker, run one FCGI
 * request through it, print the response body, shut the worker down.
 *
 * Run from the repo root:
 *
 *   php examples/embedded.php
 *
 * Expected output (on stdout):
 *
 *   Hello from PHPVerse!
 */

require __DIR__ . '/../vendor/autoload.php';

use phpverse\Embed\ForkedWorker;
use phpverse\Protocol\Constants;
use phpverse\Protocol\FcgiCodec;

$worker = ForkedWorker::spawn([
    'workerPhp' => __DIR__ . '/../worker.php',
    'workerId'  => 1,
]);

// Need the sock blocking for this single-shot demo — we just want to
// read the response synchronously. The ForkedWorker hands us a
// non-blocking stream (right for an event loop); flip it for the
// length of this script.
stream_set_blocking($worker->sock, true);

$requestId = 1;

// BEGIN_REQUEST: role=RESPONDER, flags=0 (no keep-conn → worker closes
// after END_REQUEST, which is exactly what we want for a one-shot demo).
$body = pack('nC', Constants::FCGI_RESPONDER, 0) . str_repeat("\x00", 5);
FcgiCodec::writeRecord($worker->sock, Constants::FCGI_BEGIN_REQUEST, $requestId, $body);

$params = [
    'REQUEST_METHOD'  => 'GET',
    'SCRIPT_FILENAME' => __DIR__ . '/fixtures/hello.php',
    'SCRIPT_NAME'     => '/hello.php',
    'QUERY_STRING'    => '',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'SERVER_SOFTWARE' => 'PHPVerse-embedded-demo/1.0',
];
FcgiCodec::writeRecord(
    $worker->sock,
    Constants::FCGI_PARAMS,
    $requestId,
    FcgiCodec::writeParams($params),
);
FcgiCodec::writeRecord($worker->sock, Constants::FCGI_PARAMS, $requestId, '');
FcgiCodec::writeRecord($worker->sock, Constants::FCGI_STDIN,  $requestId, '');

$stdout = '';
$stderr = '';
$exitCode = 0;

while (($rec = FcgiCodec::readRecord($worker->sock)) !== null) {
    switch ($rec->type) {
        case Constants::FCGI_STDOUT:
            $stdout .= $rec->content;
            break;
        case Constants::FCGI_STDERR:
            $stderr .= $rec->content;
            break;
        case Constants::FCGI_END_REQUEST:
            // appStatus (4 BE), protocolStatus (1), 3 reserved.
            $appStatus = unpack('N', substr($rec->content, 0, 4))[1];
            $protocolStatus = ord($rec->content[4]);
            if ($protocolStatus !== Constants::FCGI_REQUEST_COMPLETE) {
                fwrite(STDERR, "demo: protocolStatus=$protocolStatus\n");
                $exitCode = 1;
            }
            if ($appStatus !== 0) {
                fwrite(STDERR, "demo: appStatus=$appStatus\n");
                $exitCode = 1;
            }
            break 2;
    }
}

// Split off headers (Status:, Content-Type:, ...) from the body. The
// worker emits "<headers>\r\n\r\n<body>" per SPEC §4.3.
$split = strpos($stdout, "\r\n\r\n");
if ($split === false) {
    fwrite(STDERR, "demo: response had no header/body separator\nstdout:\n$stdout\n");
    $worker->close();
    exit(1);
}
$body = substr($stdout, $split + 4);

echo $body;

if ($stderr !== '') {
    fwrite(STDERR, "demo: worker stderr:\n$stderr");
}

$worker->close();
exit($exitCode);
