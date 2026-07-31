<?php
// Temporary diagnostic — safe to delete. Confirms the /admin/ dir serves PHP.
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');
echo "admin-dir-php-ok SAPI-" . PHP_SAPI . "\n";
