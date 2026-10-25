<?php
/** Real GFAPI creation/status-change fixtures for the NEW browser journey. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('Real GF API required'); }
$lab = getenv('BRC_LAB_DIR');
$cases = json_decode(file_get_contents($lab . '/independent.json'), true, 512, JSON_THROW_ON_ERROR)['cases'];
$action = (string)getenv('BRC_NEW_ACTION');
$fixture_path = $lab . '/new-independent-form.json';
$form = $action === 'setup' ? 0 : (int)(json_decode(file_get_contents($fixture_path), true)['form'] ?? 0);
$old_key = $lab . '/new-preexisting-inactive-id';
$source = GFAPI::get_entry((int)$cases['one']['entry_ids'][0]);
$url = (string)$source['1'];
$report = ['form' => $form, 'action' => $action];
if ($action === 'setup') {
    $form = GFAPI::add_form([
        'title' => 'GFBRC NEW isolated browser 154',
        'fields' => [
            ['id' => 1, 'type' => 'fileupload', 'label' => 'Single'],
            ['id' => 2, 'type' => 'fileupload', 'label' => 'Multiple', 'multipleFiles' => true],
            ['id' => 5, 'type' => 'text', 'label' => 'Group'],
        ],
        'button' => ['type' => 'text', 'text' => 'Submit'],
    ]);
    if (is_wp_error($form) || (int)$form < 1) {
        throw new RuntimeException('Isolated NEW form creation failed');
    }
    $form = (int)$form;
    for ($i = 0; $i < 154; $i++) {
        $row = ['form_id' => $form, '5' => $i < 77 ? 'group-a' : 'group-b'];
        if ($i > 0) { $row['1'] = $url; }
        if ($i === 1) { $row['2'] = wp_json_encode([$url, $url]); }
        $id = GFAPI::add_entry($row);
        if (is_wp_error($id)) { throw new RuntimeException('Isolated NEW fixture creation failed'); }
    }
    file_put_contents($fixture_path, wp_json_encode(['form' => $form]));
    $report['form'] = $form;
    $report['entries'] = 154;
    $report['files'] = 155;
} elseif ($action === 'mark_old_inactive') {
    $id = GFAPI::add_entry(['form_id' => $form, '5' => 'historical-inactive']);
    if (is_wp_error($id)) { throw new RuntimeException('Could not create inactive historical Entry'); }
    $ok = GFAPI::update_entry_property((int)$id, 'status', 'spam');
    if (is_wp_error($ok) || $ok === false) { throw new RuntimeException('Could not make old Entry inactive'); }
    file_put_contents($old_key, (string)(int)$id);
    $report['id'] = (int)$id;
} elseif ($action === 'restore_old_with_upload') {
    $old = (int)file_get_contents($old_key);
    $entry = GFAPI::get_entry($old);
    $entry['status'] = 'active';
    $entry['1'] = $url;
    $ok = GFAPI::update_entry($entry);
    if (is_wp_error($ok) || $ok === false) { throw new RuntimeException('Could not restore old Entry/upload'); }
    $report['id'] = $old;
} elseif (in_array($action, ['add_backdated', 'add_zero', 'add_missing'], true)) {
    $row = ['form_id' => $form, '5' => 'group-a'];
    if ($action === 'add_backdated') {
        $row['1'] = $url;
        $row['date_created'] = '2011-02-03 04:05:06';
    } elseif ($action === 'add_missing') {
        $row['1'] = 'http://127.0.0.1:8080/wp-content/uploads/gravity_forms/' . $form . '/not-present/new.jpg';
    }
    $id = GFAPI::add_entry($row);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $report['id'] = (int)$id;
    if ($action === 'add_zero') {
        file_put_contents($lab . '/new-latest-zero-id', (string)(int)$id);
    }
    $report['backdated'] = $action === 'add_backdated' && (GFAPI::get_entry((int)$id)['date_created'] ?? '') === $row['date_created'];
} elseif ($action === 'attach_latest_zero') {
    $id = (int)file_get_contents($lab . '/new-latest-zero-id');
    $ok = GFAPI::update_entry_field($id, 1, $url);
    if (is_wp_error($ok) || $ok === false) {
        throw new RuntimeException('Could not mutate file-free Entry before confirmation');
    }
    $report['id'] = $id;
} elseif ($action === 'read_pending') {
    global $wpdb;
    $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'gfbrc_new_pending_' . $form));
    $report['state'] = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
} elseif ($action === 'read_coverage') {
    global $wpdb;
    $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'gfbrc_new_coverage_' . $form));
    $report['state'] = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
} else {
    throw new RuntimeException('Invalid NEW fixture mutation action');
}
echo 'GFBRC_NEW_MUTATION ' . wp_json_encode($report) . PHP_EOL;
