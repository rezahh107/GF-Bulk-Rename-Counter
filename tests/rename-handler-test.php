<?php
/**
 * Source-level handler regression tests with isolated stubs (NOT an integration test).
 */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

$GLOBALS['gf_bulk_test_options'] = ['form_id' => 0, 'counter_field_id' => 5];

function get_option(string $key, $default = false) {
    return $GLOBALS['gf_bulk_test_options'];
}
function is_wp_error($value): bool {
    return $value instanceof WP_Error_Test_Double;
}
class WP_Error_Test_Double {}
function sanitize_file_name(string $name): string {
    // ASCII-only stand-in for this bounded fixture; does not assert WP sanitization parity.
    return preg_replace('/[^a-zA-Z0-9._-]/', '-', $name);
}
class GFAPI {
    public static array $entries = [];
    public static function get_entry(int $entry_id) {
        return self::$entries[$entry_id] ?? new WP_Error_Test_Double();
    }
}

require_once __DIR__ . '/../includes/class-settings_page.php';
require_once __DIR__ . '/../includes/class-rename_handler.php';

use GFBulkRename\Rename_Handler;

$checks = 0;
function expect_same(string $expected, string $actual, string $label): void {
    global $checks;
    $checks++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL {$label}: expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n");
        exit(1);
    }
    echo "PASS {$label}\n";
}

GFAPI::$entries = [
    120 => ['form_id' => '10', '5' => '1045'],
    121 => ['form_id' => '10', '5' => '1045'],
    122 => ['form_id' => '11', '5' => '0008'],
    123 => ['form_id' => '10', '5' => '0'],
    124 => ['form_id' => '10', '5' => ''],
    125 => ['form_id' => '10'],
];

$handler = new Rename_Handler();
expect_same('d1045.jpg', $handler->rename_by_counter('Entry-120/photo.jpg', 120, '/uploads/photo.jpg'), 'counter-based rename, flattened ZIP path');
expect_same('d1045-120.jpg', $handler->rename_by_counter('photo2.jpg', 120, '/uploads/photo2.jpg'), 'duplicate from same entry');
expect_same('d1045-121.jpg', $handler->rename_by_counter('photo3.jpg', 121, '/uploads/photo3.jpg'), 'duplicate from another entry');
expect_same('d1045-121-1.jpg', $handler->rename_by_counter('photo4.jpg', 121, '/uploads/photo4.jpg'), 'repeated entry-ID suffix');
expect_same('d0008.png', $handler->rename_by_counter('prior.png', 122, '/uploads/prior.png'), 'preserve leading zeros');
expect_same('original.jpg', $handler->rename_by_counter('original.jpg', 123, '/uploads/o.jpg'), 'zero is treated as empty');
expect_same('original.jpg', $handler->rename_by_counter('original.jpg', 124, '/uploads/o.jpg'), 'empty field fallback');
expect_same('original.jpg', $handler->rename_by_counter('original.jpg', 125, '/uploads/o.jpg'), 'missing field fallback');
expect_same('original.jpg', $handler->rename_by_counter('original.jpg', 999, '/uploads/o.jpg'), 'missing entry fallback');
expect_same('d1045', (new Rename_Handler())->rename_by_counter('original', 120, '/uploads/no-extension'), 'no extension');

$GLOBALS['gf_bulk_test_options'] = ['form_id' => 10, 'counter_field_id' => 5];
$filtered = new Rename_Handler();
expect_same('from-other-form.jpg', $filtered->rename_by_counter('from-other-form.jpg', 122, '/uploads/p.png'), 'non-target form unchanged');
expect_same('d1045.jpg', $filtered->rename_by_counter('photo.jpg', 120, '/uploads/photo.jpg'), 'target form renamed');

$GLOBALS['gf_bulk_test_options'] = ['form_id' => 10, 'counter_field_id' => 0];
expect_same('original.jpg', (new Rename_Handler())->rename_by_counter('original.jpg', 120, '/uploads/photo.jpg'), 'disabled counter field');

echo "{$checks} isolated checks passed; WordPress integration NOT TESTED.\n";
