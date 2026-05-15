<?php

declare(strict_types=1);

/**
 * PHPVerse worker entry point.
 *
 * Bootstrap only: reads the parent socket path from env, opens the
 * connection, hands off to {@see phpverse\Worker}. No request logic.
 */

require __DIR__ . '/vendor/autoload.php';

$sock = getenv('PHPVERSE_SOCKET');
if (!is_string($sock) || $sock === '') {
    fwrite(STDERR, "phpverse: PHPVERSE_SOCKET not set\n");
    exit(2);
}

$opts = [
    'workerId' => (int) (getenv('PHPVERSE_WORKER_ID') ?: '0'),
];
$prepend = getenv('PHPVERSE_PREPEND');
if (is_string($prepend) && $prepend !== '') {
    $opts['prepend'] = $prepend;
}

$errno = 0;
$err   = '';
$conn  = @stream_socket_client('unix://' . $sock, $errno, $err, 5.0);
if (!is_resource($conn)) {
    fwrite(STDERR, sprintf(
        "phpverse: connect %s failed: %s (errno=%d)\n",
        $sock,
        $err,
        $errno,
    ));
    exit(1);
}

try {
    $worker = new phpverse\Worker($conn, $opts);
    $worker->loop();
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf(
        "phpverse: worker crashed: %s: %s\n%s\n",
        $e::class,
        $e->getMessage(),
        $e->getTraceAsString(),
    ));
    exit(1);
}
