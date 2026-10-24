<?php
/**
 * Authentic installed WordPress process: prove ALL resource preflight rejects
 * a 503-entry scope before a ZIP is prepared. This is a bounded PHP gate test,
 * not a simulated large hosting benchmark.
 */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Real WP/GF required');
}
$method = new ReflectionMethod(\GFBulkRename\All_Download::class, 'budget');
$previous = ini_get('memory_limit');
$real = memory_get_usage(true);
$limited_mb = (int)ceil($real / 1048576) + 12;
try {
    if (ini_set('memory_limit', $limited_mb . 'M') === false) {
        throw new RuntimeException('Cannot set isolated memory budget');
    }
    try {
        $method->invoke(null, 503, 0, microtime(true));
        throw new RuntimeException('ALL admitted insufficient memory');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'Insufficient PHP memory headroom')) {
            throw $e;
        }
    }
} finally {
    ini_set('memory_limit', $previous);
}
$previous_time = ini_get('max_execution_time');
try {
    if (ini_set('max_execution_time', '2') === false) {
        throw new RuntimeException('Cannot set isolated time budget');
    }
    try {
        $method->invoke(null, 503, 0, microtime(true) - 5);
        throw new RuntimeException('ALL admitted exhausted time budget');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'Insufficient PHP execution-time budget')) {
            throw $e;
        }
    }
} finally {
    ini_set('max_execution_time', $previous_time);
}
echo 'PASS_ALL_RESOURCE_PREFLIGHT_FAIL_CLOSED_ENTRIES_503' . PHP_EOL;
