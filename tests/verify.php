<?php
/** Canonical PHP CLI verification: lint all repository PHP then execute bounded tests. */
declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$php_files = [];
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($root) + 1);
    if (str_starts_with(str_replace('\\', '/', $relative), 'vendor/')) {
        continue;
    }
    $php_files[] = $file->getPathname();
}
sort($php_files, SORT_STRING);
if (!$php_files) {
    fwrite(STDERR, "FAIL: no PHP source files found\n");
    exit(1);
}

foreach ($php_files as $file) {
    $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file);
    passthru($cmd, $status);
    if ($status !== 0) {
        exit($status);
    }
}

echo "Running isolated regression tests...\n";
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/rename-handler-test.php');
passthru($cmd, $status);
if ($status !== 0) {
    exit($status);
}
echo "Source verification PASS; WordPress integration NOT PROVEN.\n";
