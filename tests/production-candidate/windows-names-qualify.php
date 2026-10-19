<?php
/**
 * Authentic WP/GF source-preserving Windows device name fixtures for EXT-001.
 * Runs after the baseline authenticated browser ZIP tests; CI-only Entries.
 */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Authentic WP/GF required');
}
use GFBulkRename\Form_Naming_Rules as Rules;
use GFBulkRename\Flat_Zip_Download as Download;

$lab = getenv('BRC_LAB_DIR');
$manifest = json_decode(file_get_contents($lab . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR)['scenarios'];
$b = $manifest['shared']; // Single Field (text 6), existing per-form rule.
$c = $manifest['multiple']; // Composite (text 8, then text 6).
$bform = (int)$b['form_id'];
$cform = (int)$c['form_id'];
$option = Rules::OPTION_NAME;
$legacy_before = get_option('gf_bulk_rename_options');
$previous_rules = get_option($option);
if (!is_array($previous_rules) ||
    ($previous_rules[(string)$bform] ?? null) !== ['method' => 'single', 'field_id' => 6] ||
    ($previous_rules[(string)$cform] ?? null) !==
       ['method' => 'composite', 'field_ids' => [7, 6], 'separator' => '__']) {
    throw new RuntimeException('Prior per-form browser state differs from qualified baseline');
}
$original_entries = [];
$original_hashes = [];
$original_sources = [];
$qualified = json_decode(file_get_contents($lab . '/per-form.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (['b' => $b, 'c' => $c] as $role => $fixture) {
    $source_entry_id = (int)$fixture['entry_ids'][0];
    $original_entries[(string)$source_entry_id] = GFAPI::get_entry($source_entry_id);
    // Native pre-EXT-001 fixtures lack the new text fields; read sources through
    // the *already-qualified* real GF Entries that carry the selected new rule.
    $qualified_id = (int)$qualified[$role]['entry_ids'][0];
    $original_entries[(string)$qualified_id] = GFAPI::get_entry($qualified_id);
    $plan = Download::plan((int)$fixture['form_id'], [$qualified_id]);
    $paths = array_column($plan, 'source');
    $original_sources[(string)$fixture['form_id']] = $paths;
    foreach ($paths as $path) { $original_hashes[$path] = hash_file('sha256', $path); }
}
$c_definition = GF_Fields::create(['id' => 8, 'type' => 'text', 'label' => 'Windows filename second part']);
$c_existing = GFAPI::get_form($cform);
foreach ($c_existing['fields'] as $field) {
    if ((int)($field->id ?? 0) === 8) { throw new RuntimeException('Synthetic field 8 already exists'); }
}
$c_existing['fields'][] = $c_definition;
$updated = GFAPI::update_form($c_existing);
if (is_wp_error($updated)) { throw new RuntimeException($updated->get_error_message()); }
$rules = $previous_rules;
$rules[(string)$cform] = ['method' => 'composite', 'field_ids' => [8, 6], 'separator' => '.'];
// WP-CLI does not fire the admin_init registration used by options.php.
\GFBulkRename\Settings_Page::register_settings();
// Write via existing sanitizer using one-form Composite submission (not a new schema).
update_option($option, ['_form_id' => (string)$cform, '_method' => 'composite',
    '_include' => ['8' => '1', '6' => '1'],
    '_field_order' => ['8' => '1', '6' => '2'], '_separator' => '.']);
if (Rules::for_form(GFAPI::get_form($cform)) !== $rules[(string)$cform] ||
    Rules::for_form(GFAPI::get_form($bform)) !== $rules[(string)$bform] ||
    get_option('gf_bulk_rename_options') !== $legacy_before) {
    throw new RuntimeException('Composite test configuration changed unrelated rules or Legacy');
}

$cases = [];
$entry_snapshots = [];
$add = static function(string $key, array $fixture, array $values, bool $valid)
    use (&$cases, &$entry_snapshots): int {
    $parent = GFAPI::get_entry((int)$fixture['entry_ids'][0]);
    $fields = ['form_id' => (int)$fixture['form_id'], '1' => $parent['1'] ?? '',
        '2' => $parent['2'] ?? '', '5' => $parent['5'] ?? '125'] + $values;
    $id = GFAPI::add_entry($fields);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $entry_snapshots[(string)$id] = GFAPI::get_entry((int)$id);
    $cases[$key] = ['form_id' => (int)$fixture['form_id'],
        'entry_ids' => [(int)$id], 'valid' => $valid];
    return (int)$id;
};
foreach ([
    'single_CON' => 'CON', 'single_con' => 'con', 'single_NUL' => 'NUL',
    'single_COM1' => 'COM1', 'single_CON_multi_dot' => 'CON.txt',
    'single_PRN' => 'PRN', 'single_AUX' => 'AUX', 'single_LPT9' => 'LPT9',
    'single_COM_superscript' => 'COM¹', 'single_LPT_superscript' => 'LPT²'
] as $key => $value) {
    $add($key, $b, ['6' => $value, '7' => '0'], false);
}
$single_console = $add('single_CONSOLE', $b, ['6' => 'CONSOLE', '7' => '0'], true);
$add('single_unicode', $b, ['6' => 'شیراز', '7' => '0'], true);
$add('single_zero', $b, ['6' => '0', '7' => '0'], true);
foreach ([
    'composite_CON_txt' => ['CON', 'txt'],
    'composite_NUL_txt' => ['NUL', 'txt'],
    'composite_COM1_txt' => ['COM1', 'txt'],
] as $key => [$first, $second]) {
    $add($key, $c, ['8' => $first, '6' => $second, '7' => '0'], false);
}
$composite_valid = $add('composite_CONSOLE_txt', $c, ['8' => 'CONSOLE', '6' => 'txt', '7' => '0'], true);
$add('composite_zero_ordered', $c, ['8' => '0', '6' => 'شیراز', '7' => '0'], true);
$add('composite_unicode', $c, ['8' => 'شیراز', '6' => '0', '7' => '0'], true);
$cases['single_mixed_valid_then_invalid'] = ['form_id' => $bform,
    'entry_ids' => [$single_console, $cases['single_NUL']['entry_ids'][0]], 'valid' => false];
$cases['single_mixed_invalid_then_valid'] = ['form_id' => $bform,
    'entry_ids' => [$cases['single_CON']['entry_ids'][0], $single_console], 'valid' => false];
$cases['composite_mixed_valid_then_invalid'] = ['form_id' => $cform,
    'entry_ids' => [$composite_valid, $cases['composite_CON_txt']['entry_ids'][0]], 'valid' => false];

// Observe Unicode casefold-equivalent member names without inferring Windows filesystem behavior.
$unicode_ids = [];
foreach (['Ж', 'ж'] as $value) {
    $unicode_ids[] = $add('unicode_' . count($unicode_ids), $b,
        ['6' => $value, '7' => '0'], true);
}
$cases['unicode_casefold_pair'] = ['form_id' => $bform,
    'entry_ids' => $unicode_ids, 'valid' => true, 'diagnostic_only' => true];
$valid_plans = [];
foreach ($cases as $key => $scenario) {
    if (!$scenario['valid']) {
        try {
            Download::plan($scenario['form_id'], $scenario['entry_ids']);
            throw new RuntimeException('Reserved device output incorrectly planned: ' . $key);
        } catch (RuntimeException $error) {
            if (!str_contains($error->getMessage(), 'Windows reserved device')) {
                throw new RuntimeException('Unexpected rejection: ' . $key, 0, $error);
            }
        }
        continue;
    }
    $plan = Download::plan($scenario['form_id'], $scenario['entry_ids']);
    $valid_plans[$key] = array_map(static fn(array $p): array =>
        ['name' => $p['name'], 'size' => $p['size'],
         'sha256' => $p['sha256'], 'source' => $p['source']], $plan);
    // Each new test Entry has exactly the original source URLs and occurrences.
    $expected = [];
    foreach ($scenario['entry_ids'] as $id) {
        foreach ($original_sources[(string)$scenario['form_id']] as $path) { $expected[] = $path; }
    }
    $actual = array_column($plan, 'source');
    sort($expected); sort($actual);
    if ($actual !== $expected) { throw new RuntimeException('ZIP source occurrence drift: ' . $key); }
}
if (($valid_plans['single_CONSOLE'][0]['name'] ?? '') !== 'CONSOLE.jpg' ||
    ($valid_plans['single_zero'][0]['name'] ?? '') !== '0.jpg' ||
    ($valid_plans['composite_zero_ordered'][0]['name'] ?? '') !== '0.شیراز.jpg' ||
    ($valid_plans['composite_CONSOLE_txt'][0]['name'] ?? '') !== 'CONSOLE.txt.jpg') {
    throw new RuntimeException('New naming valid/ordered fixtures differ from intended filenames');
}
file_put_contents($lab . '/windows-names.json', wp_json_encode([
    'cases' => $cases, 'plans' => $valid_plans, 'entries' => $entry_snapshots,
    'original_entries' => $original_entries, 'source_hashes' => $original_hashes,
    'previous_rules' => $previous_rules, 'legacy_before' => $legacy_before
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo 'PASS_REAL_WP_GF_WINDOWS_DEVICE_PLAN_REJECTION_AND_ISOLATED_FIXTURES' . PHP_EOL;
