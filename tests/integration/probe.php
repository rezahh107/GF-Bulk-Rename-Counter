<?php
/** Genuine WP-CLI fresh bootstrap: inspect persisted setting and filter behaviour. */
if ( ! defined('ABSPATH') || ! class_exists('GFAPI') || ! function_exists('gf_apply_filters') ) {
    throw new RuntimeException('Native GF/WordPress is unavailable.');
}
$manifest = json_decode(file_get_contents(getenv('BRC_LAB_DIR') . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$s = $manifest['scenarios']['single'];
$entry_id = $s['entry_ids'][0];
$entry = GFAPI::get_entry($entry_id);
if (is_wp_error($entry) || !is_array($entry)) {
    throw new RuntimeException('Fixture entry not stored in genuine GF');
}
$options = get_option('gf_bulk_rename_options');
$source = $s['uploads'][0]['path'];
$filtered = gf_apply_filters(['bdfgf_entry_filename', $s['form_id']], $entry_id . '/single-a.jpg', $entry_id, $source);
$report = [
    'native_gf_entry_form_id' => $entry['form_id'] ?? null,
    'native_gf_entry_counter_field' => $entry['5'] ?? null,
    'stored_option' => $options,
    'base_filter_priority' => has_filter('bdfgf_entry_filename'),
    'scoped_filter_priority' => has_filter('bdfgf_entry_filename_' . $s['form_id']),
    'filter_result' => $filtered,
    'expected' => 'd125.jpg',
];
// Inspect the loaded native host class, not a test double or a source checkout.
$host_class = new ReflectionClass(\\BDFGF\\Actions\\BulkDownload::class);
$host_file = $host_class->getFileName();
$expected_host_root = realpath(WP_PLUGIN_DIR . '/bulk-download-for-gravity-forms');
if (!$host_file || !$expected_host_root || !str_starts_with(realpath($host_file), $expected_host_root . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Loaded native host source is not from the pinned installed plugin');
}
if (!$host_class->hasMethod('zip_uploaded_files')) {
    throw new RuntimeException('Installed host ZIP writer unavailable');
}
$collision = $manifest['scenarios']['host_folder_collision'];
$sources = $collision['uploads'];
if (count($sources) !== 2 ||
    basename($sources[0]['path']) !== 'same.jpg' ||
    basename($sources[1]['path']) !== 'same.jpg' ||
    dirname($sources[0]['path']) === dirname($sources[1]['path'])) {
    throw new RuntimeException('Authentic distinct-directory identical-basename prerequisite not met');
}
foreach (['custom_folder_mixed', 'host_folder_collision'] as $configured) {
    $form = GFAPI::get_form($manifest['scenarios'][$configured]['form_id']);
    if (($form['bulkDownloadSettings']['customFoldername'] ?? null) !== true ||
        ($form['bulkDownloadSettings']['downloadFoldername'] ?? null) !== 'shared') {
        throw new RuntimeException('Persisted native per-form folder setting missing: ' . $configured);
    }
}
$report['installed_host_source_sha256'] = hash_file('sha256', $host_file);
$report['configured_native_folder_scenarios'] = ['custom_folder_mixed', 'host_folder_collision'];
$report['separate_upload_directory_same_basename'] = true;
echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if (!is_array($options) || (int)($options['counter_field_id'] ?? 0) !== 5 ||
    (string)($entry['5'] ?? '') !== '125' || $filtered !== 'd125.jpg') {
    throw new RuntimeException('Fresh native filter/config/entry readback failed (diagnostic above).');
}
