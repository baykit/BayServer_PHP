<?php

// Fixture for examples/embedded.php. Runs inside the worker; its
// echo'd bytes flow back to the parent as FCGI_STDOUT.
echo "Hello from PHPVerse!\n";
