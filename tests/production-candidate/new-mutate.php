<?php
/** Real GFAPI creation/status-change fixtures for the NEW browser journey. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('Real GF API required'); }
$lab = getenv('BRC_LAB_DIR');
$cases = json_decode(file_get_contents($lab . '/independent.json'), true, 512, JSON_THROW_ON_ERROR)['cases'];
$form = (int)$cases['global154']['form_id'];
$old = (int)$cases['global154']['entry_ids'][0];
$source = GFAPI::get_entry((int)$cases['one']['entry_ids'][0]);
$url = (string)$source['1'];
$action = (string)getenv('BRC_NEW_ACTION');
$report = ['form' => $form, 'action' => $action];
if ($action === 'mark_old_inactive') {
    $ok = GFAPI::update_entry_property($old, 'status', 'spam');
    if (is_wp_error($ok) || $ok === false) { throw new RuntimeException('Could not make old Entry inactive'); }
    $report['id'] = $old;
} elseif ($action === 'restore_old_with_upload') {
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
    $report['backdated'] = $action === 'add_backdated' && (GFAPI::get_entry((int)$id)['date_created'] ?? '') === $row['date_created'];
} elseif ($action === 'read_coverage') {
    global $wpdb;
    $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'gfbrc_new_coverage_' . $form));
    $report['state'] = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
} else {
    throw new RuntimeException('Invalid NEW fixture mutation action');
}
echo 'GFBRC_NEW_MUTATION ' . wp_json_encode($report) . PHP_EOL;
