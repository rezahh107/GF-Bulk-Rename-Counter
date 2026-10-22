<?php
/** Intentional live GF membership mutation in disposable CI only. */
if (!defined('ABSPATH') || !class_exists('GFAPI') || !getenv('BRC_LAB_DIR')) {
    throw new RuntimeException('Authentic disposable WordPress/GF required');
}
$root = getenv('BRC_LAB_DIR');
$meta = json_decode(file_get_contents($root . '/independent.json'), true, 512, JSON_THROW_ON_ERROR);
$case = $meta['cases']['global154'];
$form_id = (int)$case['form_id'];
$removed_id = (int)$case['entry_ids'][10];
$entry = GFAPI::get_entry($removed_id);
if (!is_array($entry) || $entry['status'] !== 'active' || (int)$entry['form_id'] !== $form_id) {
    throw new RuntimeException('Selected mutation candidate not active');
}
$result = GFAPI::update_entry_property($removed_id, 'status', 'spam');
if (is_wp_error($result) || $result === false) {
    throw new RuntimeException('GF status mutation failed');
}
$new_id = GFAPI::add_entry(['form_id' => $form_id,
    '1' => $entry['1'], '5' => 'group-a', 'status' => 'active']);
if (is_wp_error($new_id) || (int)$new_id <= 0) {
    throw new RuntimeException('Concurrent new GF Entry not committed');
}
file_put_contents($root . '/mutation-changes.json', wp_json_encode([
    'removed_id' => $removed_id, 'inserted_id' => (int)$new_id,
    'form_id' => $form_id, 'status' => 'spam',
]));
echo 'PASS_AUTHENTIC_GF_CONCURRENT_MEMBERSHIP_CHANGED remove=' . $removed_id .
    ' inserted=' . $new_id . PHP_EOL;
