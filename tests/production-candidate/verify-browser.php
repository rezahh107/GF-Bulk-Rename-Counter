<?php
/** Read a ZIP saved by real Chromium and compare to selected GF source bytes. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('Real WP/GF unavailable'); }
$root = getenv('BRC_LAB_DIR');
foreach ([['browser-selected.json','browser-one.zip'], ['browser-two-selected.json','browser-two.zip'],
    ['browser-n99-selected.json','browser-n99.zip'],
    ['browser-n100-selected.json','browser-n100.zip'],
    ['browser-n101-selected.json','browser-n101.zip'],
    ['browser-pagination-selected.json','browser-pagination.zip']] as [$meta_file, $zip_file]) {
$meta = json_decode(file_get_contents($root . '/' . $meta_file), true, 512, JSON_THROW_ON_ERROR);
$ids = isset($meta['selected_ids']) ? array_map('intval', $meta['selected_ids']) : [(int)$meta['selected_id']];
$form_id = (int)$meta['form_id'];
$plan = \GFBulkRename\Flat_Zip_Download::plan($form_id, $ids);
$zip = new ZipArchive();
if ($zip->open($root . '/' . $zip_file) !== true) { throw new RuntimeException('Browser ZIP invalid'); }
try {
    if ($zip->numFiles !== count($plan)) { throw new RuntimeException('Browser ZIP lost occurrences'); }
    $observed = [];
    foreach ($plan as $index => $source) {
        $name = $zip->getNameIndex($index);
        if ($name !== $source['name'] || isset($observed[strtolower($name)]) ||
            str_contains($name, '/') || str_contains($name, '\\')) {
            throw new RuntimeException('Browser ZIP member naming mismatch');
        }
        $observed[strtolower($name)] = true;
        $stream = $zip->getStream($name);
        if (!$stream) { throw new RuntimeException('Browser ZIP member not readable'); }
        $hash = hash_init('sha256');
        $size = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) { throw new RuntimeException('Browser ZIP read failure'); }
            $size += strlen($chunk);
            hash_update($hash, $chunk);
        }
        fclose($stream);
        if ($size !== $source['size'] || hash_final($hash) !== $source['sha256']) {
            throw new RuntimeException('Browser ZIP content differed from original GF source');
        }
    }
    echo 'PASS_BROWSER_ZIP_SOURCE_OCCURRENCES entry_ids=' . implode(',', $ids) .
        ' members=' . count($plan) . PHP_EOL;
} finally { $zip->close(); }
}


// Native Bulk Download regression with our test-only integration installed:
// verify real browser ZIP conserves the exact selected GF Entry's file bytes.
$native_meta = json_decode(file_get_contents($root . '/browser-native-selected.json'), true, 512, JSON_THROW_ON_ERROR);
$native_plan = \GFBulkRename\Flat_Zip_Download::plan(
    (int)$native_meta['form_id'], array_map('intval', $native_meta['selected_ids'])
);
$native_zip = new ZipArchive();
if ($native_zip->open($root . '/browser-native-control.zip') !== true) {
    throw new RuntimeException('Native Bulk Download browser ZIP invalid');
}
try {
    if ($native_zip->numFiles !== count($native_plan)) {
        throw new RuntimeException('Native Bulk Download browser ZIP lost source occurrences');
    }
    $expected_hashes = array_count_values(array_column($native_plan, 'sha256'));
    $actual_hashes = [];
    $native_paths = [];
    for ($n = 0; $n < $native_zip->numFiles; $n++) {
        $name = $native_zip->getNameIndex($n);
        if (!is_string($name) || isset($native_paths[$name])) {
            throw new RuntimeException('Native Bulk Download duplicate/invalid path');
        }
        $native_paths[$name] = true;
        $stream = $native_zip->getStream($name);
        if (!$stream) { throw new RuntimeException('Native member unreadable'); }
        $ctx = hash_init('sha256');
        while (!feof($stream)) {
            $bytes = fread($stream, 65536);
            if ($bytes === false) {
                fclose($stream);
                throw new RuntimeException('Native member read failed');
            }
            hash_update($ctx, $bytes);
        }
        fclose($stream);
        $sha = hash_final($ctx);
        $actual_hashes[$sha] = ($actual_hashes[$sha] ?? 0) + 1;
    }
    ksort($actual_hashes);
    ksort($expected_hashes);
    if ($actual_hashes !== $expected_hashes) {
        throw new RuntimeException('Native Bulk Download source bytes differ with experiment active');
    }
    echo 'PASS_NATIVE_BULK_BROWSER_SOURCE_CONSERVATION members=' . count($native_paths) . PHP_EOL;
} finally { $native_zip->close(); }
