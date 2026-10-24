<?php
/**
 * Disposable two-process delayed GF commit. A real GFAPI insertion allocates
 * an Entry ID while a genuine InnoDB transaction is not yet committed.
 */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('Real GFAPI required'); }
$lab = getenv('BRC_LAB_DIR');
$barrier = getenv('BRC_NEW_BARRIER');
if (!is_string($barrier) || !str_starts_with($barrier, $lab . '/')) {
    throw new RuntimeException('Unsafe delayed-writer barrier');
}
$cases = json_decode(file_get_contents($lab . '/independent.json'), true, 512, JSON_THROW_ON_ERROR)['cases'];
$form = (int)(json_decode(file_get_contents($lab . '/new-independent-form.json'), true)['form'] ?? 0);
$source = GFAPI::get_entry((int)$cases['one']['entry_ids'][0]);
global $wpdb;
if ($wpdb->query('START TRANSACTION') === false) {
    throw new RuntimeException('Could not begin real delayed transaction');
}
$committed = false;
try {
    $id = GFAPI::add_entry(['form_id' => $form, '1' => (string)$source['1'],
        '5' => 'group-a', 'date_created' => '2010-03-04 10:11:12']);
    if (is_wp_error($id) || (int)$id <= 0) {
        throw new RuntimeException('Delayed GFAPI insertion failed');
    }
    if (file_put_contents($barrier . '.ready', (string)(int)$id) === false) {
        throw new RuntimeException('Could not signal allocated Entry');
    }
    $deadline = microtime(true) + 60;
    while (!is_file($barrier . '.go')) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Delayed commit barrier timed out'); }
        usleep(50000);
    }
    if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('GFAPI delayed COMMIT failed'); }
    $committed = true;
    echo 'PASS_REAL_GF_DELAYED_NEW_COMMIT ' . (int)$id . PHP_EOL;
} finally {
    if (!$committed) { $wpdb->query('ROLLBACK'); }
}
