<?php
/** Real disposable GF form beyond bounded request-size envelope. */
if (!defined('ABSPATH') || !class_exists('GFAPI') || !getenv('BRC_LAB_DIR')) {
    throw new RuntimeException('Authentic disposable WordPress/GF required');
}
$id = GFAPI::add_form([
    'title' => 'GFBRC beyond explicit 500 Entry bound',
    'fields' => [['id' => 1, 'type' => 'fileupload', 'label' => 'File']],
    'button' => ['type' => 'text', 'text' => 'Submit'],
]);
if (is_wp_error($id)) { throw new RuntimeException('Limit fixture form unavailable'); }
for ($n = 0; $n < 501; $n++) {
    $result = GFAPI::add_entry(['form_id' => (int)$id]);
    if (is_wp_error($result) || (int)$result <= 0) {
        throw new RuntimeException('Limit fixture Entry creation failed at ' . $n);
    }
}
file_put_contents(getenv('BRC_LAB_DIR') . '/selection-limit.json',
    wp_json_encode(['form_id' => (int)$id, 'entries' => 501]));
echo 'PASS_CREATED_AUTHENTIC_501_ENTRY_BOUNDARY_FORM' . PHP_EOL;
