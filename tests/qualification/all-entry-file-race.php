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
    foreach (['reference', 'reference_late', 'deletion', 'repeated', 'ordering', 'alias'] as $kind) {
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
        if ($kind === 'repeated') { $row['2'] = wp_json_encode([$a['url'], $a['url'], $b['url']]); }
        if ($kind === 'ordering') { $row['2'] = wp_json_encode([$a['url'], $b['url']]); }
        $id = GFAPI::add_entry($row);
        if (is_wp_error($id) || (int)$id < 1) {
            throw new RuntimeException('Cannot create ' . $kind . ' Entry');
        }
        $cases[$kind] = ['form' => (int)$form, 'entry' => (int)$id];
    }
    // Real 8 MiB physical source: timer measures the extra authoritative
    // planner/hash pass; this fixture is not a GB-scale capacity claim.
    $bench_bytes = random_bytes(8 * 1048576);
    $bench_upload = wp_upload_bits('gfbrc-all-8m-benchmark.jpg', null, $bench_bytes);
    if (!empty($bench_upload['error']) || !is_file($bench_upload['file'])) {
        throw new RuntimeException('Benchmark upload unavailable: ' . ($bench_upload['error'] ?? 'file missing'));
    }
    $bench_hash = hash('sha256', $bench_bytes);
    unset($bench_bytes);
    $bench_form = GFAPI::add_form([
        'title' => 'GFBRC ALL 8 MiB hash performance fixture',
        'fields' => [
            ['id' => 1, 'type' => 'fileupload', 'label' => 'Source'],
            ['id' => 5, 'type' => 'text', 'label' => 'Counter'],
        ],
        'button' => ['type' => 'text', 'text' => 'Submit'],
    ]);
    if (is_wp_error($bench_form)) { throw new RuntimeException('Benchmark form creation failed'); }
    $bench_entry = GFAPI::add_entry(['form_id' => (int)$bench_form,
        '1' => $bench_upload['url'], '5' => 'BENCH']);
    if (is_wp_error($bench_entry)) { throw new RuntimeException('Benchmark Entry creation failed'); }
    $cases['benchmark'] = ['form' => (int)$bench_form, 'entry' => (int)$bench_entry];
    $stored = ['cases' => $cases, 'a' => $a, 'b' => $b,
        'benchmark' => ['sha256' => $bench_hash, 'size' => 8 * 1048576,
            'url' => $bench_upload['url'], 'path' => $bench_upload['file']]];
    file_put_contents($fixture_file, wp_json_encode($stored, JSON_PRETTY_PRINT));
    $output['fixture'] = $stored;
} else {
    if (!is_array($stored)) { throw new RuntimeException('Missing real race fixture'); }
    $kind = getenv('BRC_ALL_RACE_KIND') ?: '';
    if (!isset($stored['cases'][$kind])) { throw new RuntimeException('Unknown test case'); }
    $case = $stored['cases'][$kind];
    $id = $case['entry'];
    if ($action === 'mutate') {
        if (in_array($kind, ['reference', 'reference_late', 'repeated'], true)) {
            $changed = GFAPI::update_entry_field($id, 1, $b['url']);
            if (is_wp_error($changed) || $changed === false) {
                throw new RuntimeException('GFAPI file field update failed');
            }
            if (GFAPI::get_entry($id)['1'] !== $b['url']) {
                throw new RuntimeException('GFAPI failed to persist B reference');
            }
        } elseif ($kind === 'ordering') {
            $changed = GFAPI::update_entry_field($id, 2, wp_json_encode([$b['url'], $a['url']]));
            if (is_wp_error($changed) || $changed === false) {
                throw new RuntimeException('Ordering mutation failed');
            }
        } elseif ($kind === 'alias') {
            $alias = str_replace('single-a.jpg', 'single-%61.jpg', $a['url']);
            if ($alias === $a['url'] || rawurldecode($alias) !== $a['url']) {
                throw new RuntimeException('Valid same-file encoded URL alias unavailable');
            }
            $changed = GFAPI::update_entry_field($id, 1, $alias);
            if (is_wp_error($changed) || $changed === false) {
                throw new RuntimeException('Encoded URL mutation failed');
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
        'field_2' => GFAPI::get_entry($id)['2'] ?? '',
        'plan_urls' => array_column($plan, 'url'),
        'files' => count($plan),
        'plan_sha256' => array_column($plan, 'sha256'),
        'plan_names' => array_column($plan, 'name'),
        'a_readable' => is_readable($a['path']),
        'b_readable' => is_readable($b['path']),
    ];
}
echo 'GFBRC_ALL_RACE ' . wp_json_encode($output, JSON_UNESCAPED_SLASHES) . PHP_EOL;
