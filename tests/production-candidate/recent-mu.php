<?php
/** Disposable synthetic CI only. Never part of the product archive. */
if (!defined('ABSPATH') || !getenv('BRC_LAB_DIR')) { return; }
// Isolated lab control request only: revalidate the temporarily patched PHP file
// in the HTTP worker (a separate WP-CLI process cannot invalidate its OPcache).
$revalidation = $_SERVER['HTTP_X_BRC_RECENT_ZERO_REVALIDATE'] ?? '';
if ($revalidation === '1' || $revalidation === 'all') {
    $files = $revalidation === '1' ? ['class-recent_download.php'] :
        ['class-all_download.php', 'class-flat_zip_download.php'];
    $invalidated = true;
    foreach ($files as $file) {
        $source = WP_PLUGIN_DIR . '/gf-bulk-rename-counter/includes/' . $file;
        if (!is_file($source)) { throw new RuntimeException('Missing installed test target'); }
        $invalidated = (function_exists('opcache_invalidate') && opcache_invalidate($source, true))
            && $invalidated;
    }
    header('X-GFBRC-Zero-Opcache: ' . ($invalidated ? 'invalidated' : 'not-cached-or-disabled'));
}
$fault = $_SERVER['HTTP_X_BRC_RECENT_FAULT'] ?? '';
if ($fault === 'memory') {
    add_action('wp_ajax_gfbrc_recent_prepare', static function () {
        ini_set('memory_limit', (string)(memory_get_usage(true) + 8 * 1048576));
    }, 1);
}
if ($fault === 'short-selection' || $fault === 'postwrite-source' || $fault === 'meta-read') {
    add_filter('query', static function ($sql) use ($fault) {
        if ($fault === 'short-selection' && preg_match("/ORDER BY id DESC LIMIT 50$/D", $sql)) {
            return substr($sql, 0, -2) . '49';
        }
        if ($fault === 'meta-read' && str_contains($sql, "meta_key='bdfgf_deleted_file_urls'")) {
            return 'SELECT * FROM missing_gfbrc_recent_lab_meta';
        }
        if ($fault === 'postwrite-source' && $sql === 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY') {
            static $reads = 0;
            if (++$reads === 2) {
                $fixture = json_decode(file_get_contents(getenv('BRC_LAB_DIR') . '/recent-fixture.json'), true);
                $path = $fixture['race_source']['path'];
                $bytes = file_get_contents($path);
                file_put_contents($path, 'X' . substr($bytes, 1));
            }
        }
        return $sql;
    });
}
