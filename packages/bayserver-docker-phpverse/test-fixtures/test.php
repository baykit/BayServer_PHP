<?php
// Minimal smoke fixture for PhpVerseDocker.
//
// Echoed body is the marker string the smoke-test.sh shell script
// greps for. The current time appended at the end exercises that
// each request's output is captured fresh (= isolation §5.1).
echo "hello from phpverse\n";
echo "now=" . microtime(true) . "\n";
