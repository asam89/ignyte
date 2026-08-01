<?php
// Temporary diagnostic — safe to delete. Locates config.php on the server.
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');

echo "root-php-ok SAPI-" . PHP_SAPI . "\n";
$here = __DIR__;
echo "here=" . $here . "\n";

$candidates = [
    $here . '/admin/config.php',
    $here . '/public_html/admin/config.php',
    dirname($here) . '/public_html/admin/config.php',
    dirname($here) . '/admin/config.php',
];
foreach ($candidates as $c) {
    echo (file_exists($c) ? 'FOUND ' : 'no    ') . $c . "\n";
}

// Shallow recursive search for any config.php near the web root.
$roots = [$here, dirname($here)];
foreach ($roots as $root) {
    try {
        $dir = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $it = new RecursiveIteratorIterator(
            $dir,
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        $it->setMaxDepth(3);
        foreach ($it as $f) {
            if ($f->getFilename() === 'config.php') {
                echo 'CONFIG@ ' . $f->getPathname() . ' (' . $f->getSize() . " bytes)\n";
            }
        }
    } catch (Exception $e) {
        echo 'scan-error(' . $root . '): ' . $e->getMessage() . "\n";
    }
}
echo "done\n";
