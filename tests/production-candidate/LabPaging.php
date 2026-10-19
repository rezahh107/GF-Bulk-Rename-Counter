<?php
/** Disposable test-only page-size accommodation. Not shipped in plugin ZIP. */
if (!defined('ABSPATH')) { exit; }
if (getenv('BRC_LAB_DIR')) {
    add_filter('gform_entry_page_size', static function($size, $form_id) {
        if (is_file(getenv('BRC_LAB_DIR') . '/ifz-native-page-size')) { return $size; }
        return 160;
    }, 10, 2);
    // Test-only instrumentation of the actual installed production request.
    add_action('shutdown', static function() {
        if (($_POST['action'] ?? '') !== 'gfbrc_flat_zip_download') { return; }
        $usage = function_exists('getrusage') ? getrusage() : [];
        error_log('GFBRC_CANDIDATE_RESOURCE_LAB ' . wp_json_encode([
            'php_peak_allocated_bytes' => memory_get_peak_usage(true),
            'process_max_rss_kib_linux' => $usage['ru_maxrss'] ?? null,
            'note' => 'includes WordPress bootstrap and runtime, not ZIP writer increment',
        ]));
    });
}
