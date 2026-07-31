<?php
// Temporary diagnostic — safe to delete. Reveals whether config.php loads.
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');

echo "root-php-ok\n";
$cfg = __DIR__ . '/admin/config.php';
echo file_exists($cfg) ? "config-exists\n" : "config-MISSING\n";
require $cfg;
echo "config-included\n";
echo defined('DB_PASS') ? ('DB_PASS-len=' . strlen(DB_PASS) . "\n") : "DB_PASS-undefined\n";
echo defined('RESEND_API_KEY') ? ('RESEND-len=' . strlen(RESEND_API_KEY) . "\n") : "RESEND-undefined\n";
echo "PHP-" . PHP_VERSION . " SAPI-" . PHP_SAPI . "\n";
echo "done\n";
