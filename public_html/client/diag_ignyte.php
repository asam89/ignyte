<?php
// Temporary diagnostic — safe to delete. Confirms the /client/ dir serves PHP.
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');
echo "client-dir-php-ok SAPI-" . PHP_SAPI . "\n";
