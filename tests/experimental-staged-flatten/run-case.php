<?php
/** Test-only callback registration followed by real native bulk action. */
if (!defined('ABSPATH') || !class_exists('GFAPI') || !class_exists(ZipArchive::class)) {
    throw new RuntimeException('SF_AUTHENTIC_WP_AND_ZIP_REQUIRED');
}
$scenario = getenv('BRC_SF_SCENARIO');
if (!$scenario || !preg_match('/^[a-z0-9_]+$/D', $scenario)) {
    throw new RuntimeException('SF_INVALID_SCENARIO');
}
// This is a deliberately external, late naming filter applied BEFORE staging.
if ($scenario === 'sf_late_filter') {
    add_filter('bdfgf_entry_filename', static fn($path) => 'forced-same.jpg', 999, 3);
}
// This is a deliberately competing filter applied AFTER staging. The host
// may overwrite its first member before the final callback sees the ZIP.
if ($scenario === 'sf_post_stage_filename') {
    add_filter('bdfgf_entry_filename', static fn($path) => 'collapsed.jpg', 1001, 3);
}
require __DIR__ . '/Adapter.php';
$adapter = new GFBRC_Staged_Flatten_Experiment($scenario);
$adapter->register();

if (str_starts_with($scenario, 'sf_hook_')) {
    $priority = str_starts_with($scenario, 'sf_hook_early') ? 5 : 20;
    add_action('bdfgf_after_uploaded_files', static function($zip) use ($scenario) {
        $lab = getenv('BRC_LAB_DIR');
        $file = $lab . '/sf-' . $scenario . '.json';
        if (!$zip instanceof ZipArchive || !is_file($file)) {
            throw new RuntimeException('SF_HOOK_PLAN_NOT_AVAILABLE');
        }
        $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $first = $data['occurrences'][0];
        if (str_contains($scenario, 'extra')) {
            $result = $zip->addFromString('third-party-extra.txt', 'unexpected bytes');
        } elseif (str_contains($scenario, 'same_bytes')) {
            $result = $zip->addFile($first['source'], $first['member']);
        } else {
            $result = $zip->addFromString($first['member'], 'third-party changed bytes');
        }
        if (!$result) {
            throw new RuntimeException('SF_THIRD_PARTY_INJECTION_FAILED');
        }
        file_put_contents($lab . '/sf-hook-' . $scenario . '.txt', 'invoked');
    }, $priority, 3);
}
require __DIR__ . '/../integration/download.php';
