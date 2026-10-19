<?php
/** Post-download readback of *all* synthetic GF Entries and source files. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('Authentic GF required'); }
$root = getenv('BRC_LAB_DIR');
$snapshots = json_decode(file_get_contents($root . '/independent-snapshots.json'), true, 512, JSON_THROW_ON_ERROR);
$plans = json_decode(file_get_contents($root . '/independent-plans.json'), true, 512, JSON_THROW_ON_ERROR);
$unreadable = trim(file_get_contents($root . '/independent-unreadable-path.txt'));
if (!is_file($unreadable) || !chmod($unreadable, 0600)) {
    throw new RuntimeException('Cannot restore synthetic unreadable upload for immutability readback');
}
$count = 0;
foreach ($snapshots as $case => $entries) {
    foreach ($entries as $id => $original) {
        $now = GFAPI::get_entry((int)$id);
        if (!is_array($now) || is_wp_error($now)) { throw new RuntimeException('Entry missing: ' . $id); }
        foreach (['1', '2', '5'] as $field) {
            if (($now[$field] ?? null) !== $original[$field]) {
                throw new RuntimeException('Synthetic Entry mutated: ' . $id . '/' . $field);
            }
        }
        if ((int)$now['form_id'] !== (int)$original['form_id'] ||
            gform_get_meta((int)$id, 'bdfgf_deleted_file_urls') !== $original['deleted'] ||
            gform_get_meta((int)$id, 'bdfgf_files_deleted') !== $original['deleted_flag']) {
            throw new RuntimeException('Synthetic Entry metadata changed: ' . $id);
        }
        $count++;
    }
}
$unique = [];
foreach ($plans as $case => $plan) {
    if (!is_array($plan) || isset($plan['error'])) { continue; }
    foreach ($plan as $item) {
        $unique[$item['source']] = $item['sha256'];
    }
}
foreach ($unique as $path => $sha) {
    if (!is_file($path) || !is_readable($path) || hash_file('sha256', $path) !== $sha) {
        throw new RuntimeException('Original uploaded source modified: ' . $path);
    }
}
echo 'PASS_INDEPENDENT_ORIGINAL_GF_ENTRIES_AND_UPLOADS_UNCHANGED snapshots=' .
    $count . ' unique_sources=' . count($unique) . PHP_EOL;
