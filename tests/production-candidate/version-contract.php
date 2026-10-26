<?php
/** Isolated simulated host-version change: production metadata code, NOT a published host. */
define('ABSPATH', '/');
define('BDFGF_VERSION', '999.0-simulated');
// Match the en_US identity behavior of WordPress gettext in this isolated host.
function __(string $message, string $domain): string {
    if ($domain !== 'gf-bulk-rename-counter') {
        throw new RuntimeException('Unexpected translation domain');
    }
    return $message;
}
$records = [];
function gform_get_meta($entry_id, $key) { global $records; return $records[$entry_id][$key] ?? ''; }
function maybe_unserialize($value) { return $value; }
require dirname(__DIR__, 2) . '/includes/class-flat_zip_download.php';
$method = new ReflectionMethod(\GFBulkRename\Flat_Zip_Download::class, 'deleted_urls_for_entry');
$records = [
    1 => ['bdfgf_files_deleted' => true, 'bdfgf_deleted_file_urls' => ['https://sample.invalid/a.jpg']],
    2 => ['bdfgf_files_deleted' => '', 'bdfgf_deleted_file_urls' => ''],
    3 => ['bdfgf_files_deleted' => true, 'bdfgf_deleted_file_urls' => []],
    4 => ['bdfgf_files_deleted' => 'unknown', 'bdfgf_deleted_file_urls' => []],
    5 => ['bdfgf_files_deleted' => false, 'bdfgf_deleted_file_urls' => ['https://sample.invalid/a.jpg']],
    6 => ['bdfgf_files_deleted' => true, 'bdfgf_deleted_file_urls' => ['invalid' => 'https://sample.invalid/a.jpg']],
];
if ($method->invoke(null, 1) !== ['https://sample.invalid/a.jpg' => true] ||
    $method->invoke(null, 2) !== []) { throw new RuntimeException('Simulated version compatible contract rejected'); }
foreach ([3,4,5,6] as $id) {
    try { $method->invoke(null, $id); throw new RuntimeException('Bad metadata accepted: ' . $id); }
    catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'accepted')) { throw $e; }
    }
}
echo 'PASS_SIMULATED_VERSION_STRING_WITH_COMPATIBLE_DELETION_METADATA' . PHP_EOL;
