<?php
/** Disposable source-unique Entries for documented Entry List hook qualification. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Authentic WordPress and Gravity Forms required');
}
$lab = getenv('BRC_LAB_DIR');
if (!$lab || !is_dir($lab)) { throw new RuntimeException('Disposable lab directory unavailable'); }
$form_id = GFAPI::add_form([
    'title' => 'GFBRC hooked Entry List membership qualification',
    'fields' => [
        ['id' => 1, 'type' => 'fileupload', 'label' => 'Upload'],
        ['id' => 5, 'type' => 'text', 'label' => 'Group'],
        ['id' => 6, 'type' => 'text', 'label' => 'Eligibility'],
    ],
    'button' => ['type' => 'text', 'text' => 'Submit'],
]);
if (is_wp_error($form_id) || (int)$form_id <= 0) { throw new RuntimeException('Test form creation failed'); }
$fixture = ['form_id' => (int)$form_id, 'included' => [], 'excluded' => [], 'files' => []];
foreach (['included' => 'hook-a', 'excluded' => 'hook-b'] as $group => $value) {
    for ($i = 1; $i <= 3; $i++) {
        $name = sprintf('gfbrc-hook-%s-%d.jpg', $value, $i);
        $source = 'GFBRC_AUTHENTIC_HOOK_PROBE:' . $name . ':' . str_repeat($value, $i);
        $upload = wp_upload_bits($name, null, $source);
        if (!empty($upload['error']) || !is_file($upload['file'])) {
            throw new RuntimeException('Cannot create authentic WP upload: ' . $name);
        }
        $id = GFAPI::add_entry([
            'form_id' => (int)$form_id, '1' => $upload['url'],
            '5' => $value, '6' => $i <= 2 ? 'allow' : 'deny', 'status' => 'active',
        ]);
        if (is_wp_error($id) || (int)$id <= 0) { throw new RuntimeException('GF entry creation failed'); }
        $fixture[$group][] = (int)$id;
        $fixture['files'][] = [
            'entry_id' => (int)$id, 'name' => $name, 'group' => $value,
            'sha256' => hash_file('sha256', $upload['file']),
            'url' => $upload['url'],
        ];
    }
}
$unfiltered = GFAPI::count_entries((int)$form_id, ['status' => 'active']);
$filtered = GFAPI::count_entries((int)$form_id, [
    'status' => 'active', 'field_filters' => [['key' => '5', 'value' => 'hook-a']],
]);
if ($unfiltered !== 6 || $filtered !== 3) {
    throw new RuntimeException('GFAPI groups/field filters not functioning: ' . $unfiltered . '/' . $filtered);
}
file_put_contents($lab . '/hook-fixture.json', wp_json_encode($fixture, JSON_PRETTY_PRINT));
echo 'PASS_HOOK_FIXTURE_REAL_GF form_id=' . (int)$form_id .
    ' included_ids=' . implode(',', $fixture['included']) .
    ' excluded_ids=' . implode(',', $fixture['excluded']) .
    ' unfiltered=6 filtered=3' . PHP_EOL;
