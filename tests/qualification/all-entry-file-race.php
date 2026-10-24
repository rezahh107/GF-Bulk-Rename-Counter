<?php
/** Authentic GF mutation probe; test-only code, no production plugin changes. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Authentic WordPress / Gravity Forms required');
}
$lab = getenv('BRC_LAB_DIR');
$action = getenv('BRC_ALL_RACE_ACTION') ?: '';
$manifest = json_decode(file_get_contents($lab . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$a = $manifest['scenarios']['single']['uploads'][0];
$b = $manifest['scenarios']['shared']['uploads'][1];
if ($a['sha256'] === $b['sha256'] ||
    hash_file('sha256', $a['path']) !== $a['sha256'] ||
    hash_file('sha256', $b['path']) !== $b['sha256']) {
    throw new RuntimeException('Distinct readable A/B sources unavailable');
}
$fixture_file = $lab . '/all-race-fixture.json';
$stored = is_file($fixture_file) ?
    json_decode(file_get_contents($fixture_file), true, 512, JSON_THROW_ON_ERROR) : null;
$output = ['action' => $action];
if ($action === 'setup') {
    if ($stored !== null) { throw new RuntimeException('Race fixture already initialized'); }
    $cases = [];
    foreach (['reference', 'deletion'] as $kind) {
        $form = GFAPI::add_form([
            'title' => 'GFBRC ALL mutation race ' . $kind,
            'fields' => [
                ['id' => 1, 'type' => 'fileupload', 'label' => 'Source A'],
                ['id' => 2, 'type' => 'fileupload', 'label' => 'Source B',
                    'multipleFiles' => true],
                ['id' => 5, 'type' => 'text', 'label' => 'Legacy counter'],
            ],
            'button' => ['type' => 'text', 'text' => 'Submit'],
        ]);
        if (is_wp_error($form) || (int)$form < 1) {
            throw new RuntimeException('Cannot create ' . $kind . ' form');
        }
        $row = ['form_id' => (int)$form, '1' => $a['url'], '5' => 'RACE'];
        if ($kind === 'deletion') { $row['2'] = wp_json_encode([$b['url']]); }
        $id = GFAPI::add_entry($row);
        if (is_wp_error($id) || (int)$id < 1) {
            throw new RuntimeException('Cannot create ' . $kind . ' Entry');
        }
        $cases[$kind] = ['form' => (int)$form, 'entry' => (int)$id];
    }
    $stored = ['cases' => $cases, 'a' => $a, 'b' => $b];
    file_put_contents($fixture_file, wp_json_encode($stored, JSON_PRETTY_PRINT));
    $output['fixture'] = $stored;
} else {
    if (!is_array($stored)) { throw new RuntimeException('Missing real race fixture'); }
    $kind = getenv('BRC_ALL_RACE_KIND') ?: '';
    if (!isset($stored['cases'][$kind])) { throw new RuntimeException('Unknown test case'); }
    $case = $stored['cases'][$kind];
    $id = $case['entry'];
    if ($action === 'mutate') {
        if ($kind === 'reference') {
            $changed = GFAPI::update_entry_field($id, 1, $b['url']);
            if (is_wp_error($changed) || $changed === false) {
                throw new RuntimeException('GFAPI file field update failed');
            }
            if (GFAPI::get_entry($id)['1'] !== $b['url']) {
                throw new RuntimeException('GFAPI failed to persist B reference');
            }
        } else {
            gform_update_meta($id, 'bdfgf_deleted_file_urls', [$a['url']]);
            gform_update_meta($id, 'bdfgf_files_deleted', true);
            $list = maybe_unserialize(gform_get_meta($id, 'bdfgf_deleted_file_urls'));
            $flag = gform_get_meta($id, 'bdfgf_files_deleted');
            if ($list !== [$a['url']] || !in_array($flag, [true, 1, '1'], true)) {
                throw new RuntimeException('Deletion metadata mutation did not persist');
            }
        }
    } elseif ($action !== 'inspect') {
        throw new RuntimeException('Unsupported mutation command');
    }
    if (GFAPI::get_entry($id)['status'] !== 'active') {
        throw new RuntimeException('Entry membership changed, test invalid');
    }
    $plan = \GFBulkRename\Flat_Zip_Download::plan($case['form'], [$id]);
    $output += [
        'kind' => $kind, 'form' => $case['form'], 'entry' => $id,
        'field_1' => GFAPI::get_entry($id)['1'],
        'files' => count($plan),
        'plan_sha256' => array_column($plan, 'sha256'),
        'plan_names' => array_column($plan, 'name'),
        'a_readable' => is_readable($a['path']),
        'b_readable' => is_readable($b['path']),
    ];
}
echo 'GFBRC_ALL_RACE ' . wp_json_encode($output, JSON_UNESCAPED_SLASHES) . PHP_EOL;
