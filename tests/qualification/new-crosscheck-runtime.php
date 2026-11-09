<?php
/** Authentic isolated WP/GF cross-check; fault injections live only in memory. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Authentic WordPress/GF runtime required');
}
$lab = getenv('BRC_LAB_DIR');
$root = is_string($lab) ? realpath($lab) : false;
if (!$root || !str_starts_with($root, '/tmp/brc-lab')) {
    throw new RuntimeException('Only disposable lab allowed');
}
$fixture = json_decode(file_get_contents($root . '/new-real-form-meta.json'), true, 512, JSON_THROW_ON_ERROR);
$name = getenv('BRC_CROSSCHECK_CASE');
if (!in_array($name, ['optional','multi'], true) || !isset($fixture[$name]['form'])) {
    throw new RuntimeException('Unknown synthetic fixture');
}
$form = (int)$fixture[$name]['form'];
global $wpdb;
$coverage_before = $wpdb->get_var($wpdb->prepare(
    "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
    'gfbrc_new_coverage_' . $form
));
$pending_before = $wpdb->get_var($wpdb->prepare(
    "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
    'gfbrc_new_pending_' . $form
));
$coverage = json_decode($coverage_before, true, 512, JSON_THROW_ON_ERROR);
$pending = json_decode($pending_before, true, 512, JSON_THROW_ON_ERROR);
if (!in_array($pending['status'] ?? '', ['prepared','ready'], true) ||
    !is_array($pending['ids'] ?? null) || !$pending['ids']) {
    throw new RuntimeException('Authentic NEW prepared generation missing');
}
$table = GFFormsModel::get_entry_table_name();
if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
    throw new RuntimeException('Unsafe GF Entry table');
}
if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false ||
    $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT') === false) {
    throw new RuntimeException('Consistent source view unavailable');
}
$in_tx = true;
try {
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id,status FROM {$table} WHERE form_id=%d ORDER BY id ASC LIMIT 41", $form
    ), ARRAY_A);
    if (!is_array($rows) || count($rows) >= 41) {
        throw new RuntimeException('Bounded synthetic population unavailable');
    }
    $observed = \GFBulkRename\New_Identity_Crosscheck::observe($form, $rows,
        $coverage['known'], $coverage['covered']);
    if (!$observed['complete']) {
        throw new RuntimeException('Real independent GF observation unavailable');
    }
    if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Read-only COMMIT failed'); }
    $in_tx = false;
} finally {
    if ($in_tx) { $wpdb->query('ROLLBACK'); }
}
$before = \GFBulkRename\New_Identity_Crosscheck::sample_sources($form, $observed);
try {
    $plan = \GFBulkRename\Flat_Zip_Download::plan($form, $pending['ids']);
} catch (\GFBulkRename\No_Eligible_Files_Exception $e) {
    $plan = [];
}
$after = \GFBulkRename\New_Identity_Crosscheck::sample_sources($form, $observed);
$stable = \GFBulkRename\New_Identity_Crosscheck::stable_entries($form, $observed);
$good = \GFBulkRename\New_Identity_Crosscheck::evaluate($observed,
    $pending['ids'], $plan, $before, $after, $stable);
if ($good['classification'] !== 'NO_OBSERVED_MISMATCH' ||
    $good['eligible_entries'] !== count($pending['ids']) ||
    $good['eligible_files'] !== count($plan)) {
    throw new RuntimeException('Authentic normal control failed: ' . wp_json_encode($good));
}
if ($name === 'optional') {
    if (count($pending['ids']) !== 1 || count($plan) !== 0) {
        throw new RuntimeException('Real zero-file fixture invalid');
    }
    $reduced_ids = [];
    $negative = \GFBulkRename\New_Identity_Crosscheck::evaluate($observed,
        $reduced_ids, $plan, $before, $after, $stable);
    $reason = 'eligible_entry_missing_from_NEW_selection';
} else {
    if (count($plan) !== 3) { throw new RuntimeException('Real multi-file fixture invalid'); }
    $reduced_plan = array_slice($plan, 0, -1);
    $negative = \GFBulkRename\New_Identity_Crosscheck::evaluate($observed,
        $pending['ids'], $reduced_plan, $before, $after, $stable);
    $reason = 'eligible_file_occurrence_missing_from_NEW_plan';
}
if ($negative['classification'] !== 'CONFIRMED_MISMATCH' ||
    !in_array($reason, array_column($negative['discrepancies'], 'reason'), true)) {
    throw new RuntimeException('Real independent omission not detected: ' . wp_json_encode($negative));
}
$coverage_after = $wpdb->get_var($wpdb->prepare(
    "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
    'gfbrc_new_coverage_' . $form
));
$pending_after = $wpdb->get_var($wpdb->prepare(
    "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
    'gfbrc_new_pending_' . $form
));
if ($coverage_before !== $coverage_after || $pending_before !== $pending_after) {
    throw new RuntimeException('Diagnostic unexpectedly changed ACK/coverage or pending');
}
echo 'PASS_REAL_GF_INDEPENDENT_NEW_' . strtoupper($name) . '_OMISSION_CONTROL' . PHP_EOL;
