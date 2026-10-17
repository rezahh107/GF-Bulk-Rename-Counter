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
echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if (!is_array($options) || (int)($options['counter_field_id'] ?? 0) !== 5 ||
    (string)($entry['5'] ?? '') !== '125' || $filtered !== 'd125.jpg') {
    throw new RuntimeException('Fresh native filter/config/entry readback failed (diagnostic above).');
}
