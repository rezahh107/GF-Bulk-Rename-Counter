<?php
/** Disposable test-only page-size accommodation. Not shipped in plugin ZIP. */
if (!defined('ABSPATH')) { exit; }
if (getenv('BRC_LAB_DIR')) {
    // Disposable coordination only: pause a genuine HTTP all-matching
    // read AFTER its consistent snapshot begins, allowing a separate
    // WP-CLI connection to mutate live GF membership.
    add_filter('query', static function($sql) {
        static $snapshot = false, $paused = false;
        if (($_POST['gfbrc_mutation_lab'] ?? '') !== 'snapshot-membership') { return $sql; }
        if (stripos(ltrim($sql), 'START TRANSACTION WITH CONSISTENT SNAPSHOT') === 0) {
            $snapshot = true;
            return $sql;
        }
        if (!$snapshot || $paused || !preg_match('/^\s*SELECT\b/i', $sql) ||
            stripos($sql, 'gf_entry') === false) {
            return $sql;
        }
        $paused = true;
        $root = getenv('BRC_LAB_DIR');
        file_put_contents($root . '/mutation.ready', 'inside-snapshot-gf-query');
        $deadline = microtime(true) + 30;
        while (!is_file($root . '/mutation.go')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Synthetic snapshot mutation barrier timeout');
            }
            usleep(25000);
        }
        return $sql;
    });
    add_filter('gform_entry_page_size', static function($size, $form_id) {
        if (is_file(getenv('BRC_LAB_DIR') . '/ifz-native-page-size')) { return $size; }
        return 160;
    }, 10, 2);
    // Controlled output-buffer fixtures for authenticated admin-post requests.
    // This mu-plugin exists only inside disposable authentic CI WordPress.
    add_action('admin_post_gfbrc_flat_zip_download', static function() {
        $case = $_POST['gfbrc_buffer_lab'] ?? '';
        if (!is_string($case) || !in_array($case, ['removable', 'nested', 'nonremovable', 'stack'], true)) {
            return;
        }
        $non_removable = PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_REMOVABLE;
        if ($case === 'nonremovable' || $case === 'stack') {
            ob_start(null, 0, $non_removable);
        }
        if ($case === 'removable' || $case === 'nested' || $case === 'stack') {
            ob_start();
        }
        if ($case === 'nested' || $case === 'stack') {
            ob_start();
        }
    }, 1);
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
