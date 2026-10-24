<?php
/** Real GF-only fixtures for independent ALL, including >500 active Entries. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('Installed GF is required'); }
$lab = getenv('BRC_LAB_DIR');
$fixture = $lab . '/all-independent-form.json';
$action = (string)getenv('BRC_ALL_ACTION');
$base = json_decode(file_get_contents($lab . '/independent.json'), true, 512, JSON_THROW_ON_ERROR);
$origin = GFAPI::get_entry((int)$base['cases']['one']['entry_ids'][0]);
$url = $origin['1'];
$data = is_file($fixture) ? json_decode(file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR) : [];
$form = (int)($data['form'] ?? 0);
$answer = ['action' => $action];
if ($action === 'setup') {
    if ($form) { throw new RuntimeException('ALL fixture already initialized'); }
    $form = GFAPI::add_form([
        'title' => 'GFBRC ALL complete 503 Entry fixture',
        'fields' => [
            ['id' => 1, 'type' => 'fileupload', 'label' => 'Single'],
            ['id' => 2, 'type' => 'fileupload', 'label' => 'Multiple', 'multipleFiles' => true],
            ['id' => 5, 'type' => 'text', 'label' => 'Group'],
        ],
        'button' => ['type' => 'text', 'text' => 'Submit'],
    ]);
    if (is_wp_error($form) || !$form) { throw new RuntimeException('ALL test form creation failed'); }
    $ids = [];
    for ($i = 0; $i < 503; $i++) {
        $values = ['form_id' => (int)$form, '5' => $i < 250 ? 'group-a' : 'group-b'];
        if ($i !== 0) { $values['1'] = $url; }
        if ($i === 1) { $values['2'] = wp_json_encode([$url, $url]); }
        $id = GFAPI::add_entry($values);
        if (is_wp_error($id)) { throw new RuntimeException('ALL fixture add_entry failed'); }
        $ids[] = (int)$id;
    }
    $inactive = GFAPI::add_entry(['form_id' => (int)$form, '1' => $url]);
    if (is_wp_error($inactive) ||
        is_wp_error(GFAPI::update_entry_property((int)$inactive, 'status', 'spam'))) {
        throw new RuntimeException('Cannot create excluded spam Entry');
    }
    file_put_contents($fixture, wp_json_encode(['form' => $form, 'ids' => $ids, 'inactive' => (int)$inactive]));
    $answer += ['form' => (int)$form, 'entries' => 503, 'files' => 504, 'zero' => 1];
} elseif ($action === 'add_member' || $action === 'add_missing') {
    $values = ['form_id' => $form, '5' => 'group-b'];
    if ($action === 'add_member') {
        $values['1'] = $url;
    } else {
        $values['1'] = 'http://127.0.0.1:8080/wp-content/uploads/gravity_forms/' . $form . '/missing/file.jpg';
    }
    $id = GFAPI::add_entry($values);
    if (is_wp_error($id)) { throw new RuntimeException('ALL mutation failed'); }
    $answer += ['form' => $form, 'new_id' => (int)$id];
} elseif ($action === 'remove_member') {
    $id = (int)($data['ids'][1] ?? 0);
    if (!$id || is_wp_error(GFAPI::update_entry_property($id, 'status', 'spam'))) {
        throw new RuntimeException('ALL status mutation failed');
    }
    $answer += ['form' => $form, 'inactive_id' => $id];
} else {
    throw new RuntimeException('Unexpected ALL test fixture operation');
}
echo 'GFBRC_ALL_FIXTURE ' . wp_json_encode($answer) . PHP_EOL;
