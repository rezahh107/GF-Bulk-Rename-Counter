<?php
/** PHP CLI subprocess: exercise the actual production buffer-cleanup method. */
declare(strict_types=1);
if ($argc < 2) { fwrite(STDERR, "Missing case\n"); exit(2); }
define('ABSPATH', '/');
require $argv[2] ?? dirname(__DIR__, 2) . '/includes/class-flat_zip_download.php';
$case = $argv[1];
if (!in_array($case, ['none', 'removable', 'nested', 'nonremovable', 'stack'], true)) {
    fwrite(STDERR, "Unknown case\n"); exit(2);
}
$original_level = ob_get_level();
$non_removable = PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_REMOVABLE;
if ($case === 'nonremovable' || $case === 'stack') {
    ob_start(null, 0, $non_removable);
}
if (in_array($case, ['removable', 'nested', 'stack'], true)) {
    ob_start();
}
if ($case === 'nested' || $case === 'stack') {
    ob_start();
}
if (ob_get_level() > $original_level) {
    echo 'BUFFER_CONTENT_MUST_NOT_ENTER_ZIP';
}
$failed = false;
try {
    $method = new ReflectionMethod(\GFBulkRename\Flat_Zip_Download::class, 'clear_output_buffers');
    $method->invoke(null);
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'Cannot remove output buffer before ZIP response')) {
        fwrite(STDERR, "Wrong failure: " . $e->getMessage() . "\n");
        exit(1);
    }
    $failed = true;
}
$expected_failure = in_array($case, ['nonremovable', 'stack'], true);
$expected_level = $original_level + ($expected_failure ? 1 : 0);
if ($failed !== $expected_failure || ob_get_level() !== $expected_level ||
    headers_sent()) {
    fwrite(STDERR, "Incorrect cleanup state for " . $case . " level=" .
        ob_get_level() . " expected=" . $expected_level . " failed=" . (int)$failed . "\n");
    exit(1);
}
fwrite(STDERR, "PASS_BUFFER_GUARD " . $case . " level=" . ob_get_level() . "\n");
