<?php
/**
 * EXT-001 real WP/GF fixture and Settings API persistence qualification.
 * Execute only in the isolated CI lab after baseline ZIP/browser checks.
 */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('Authentic WP/GF required'); }
use GFBulkRename\Form_Naming_Rules as Rules;
use GFBulkRename\Flat_Zip_Download as Download;

$root = getenv('BRC_LAB_DIR');
$scenarios = json_decode(file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR)['scenarios'];
$a = $scenarios['single']; $b = $scenarios['shared']; $c = $scenarios['multiple'];
$legacy_before = get_option('gf_bulk_rename_options');
if ($legacy_before !== ['form_id' => 0, 'counter_field_id' => 5]) {
    throw new RuntimeException('Unexpected pre-upgrade Legacy configuration');
}
$option = Rules::OPTION_NAME;
delete_option($option);
if (Download::plan((int)$a['form_id'], $a['entry_ids'])[0]['name'] !== 'd125.jpg') {
    throw new RuntimeException('Legacy fallback changed before per-form configuration');
}
foreach ([$b, $c] as $target) {
    $form = GFAPI::get_form((int)$target['form_id']);
    foreach ([['id' => 6, 'type' => 'text', 'label' => 'Name'], 
              ['id' => 7, 'type' => 'number', 'label' => 'Number']] as $definition) {
        $field = GF_Fields::create($definition);
        if (!is_object($field)) { throw new RuntimeException('Cannot create authentic GF field'); }
        $form['fields'][] = $field;
    }
    $result = GFAPI::update_form($form);
    if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
    if (count(Rules::eligible_fields(GFAPI::get_form((int)$target['form_id']))) < 3) {
        throw new RuntimeException('New GF fields not persisted');
    }
}
$add = function(array $case, array $values): int {
    $entry = GFAPI::get_entry((int)$case['entry_ids'][0]);
    $new = ['form_id' => (int)$case['form_id'],
        '1' => $entry['1'] ?? '', '2' => $entry['2'] ?? '', '5' => '125'] + $values;
    $id = GFAPI::add_entry($new);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    return (int)$id;
};
$bids = [
    $add($b, ['6' => 'A?B', '7' => '4']),
    $add($b, ['6' => 'AB', '7' => '5']),
];
$cids = [
    $add($c, ['6' => 'شیراز', '7' => '0']),
    $add($c, ['6' => 'شیراز', '7' => '0']),
];
$invalid_id = $add($c, ['6' => '', '7' => '3']);
$source_before = [];
$entry_before = [];
foreach ([$bids, $cids, [$invalid_id]] as $group) {
    foreach ($group as $id) {
        $entry_before[(string)$id] = GFAPI::get_entry($id);
    }
}
// Source hashes are checked from actual GF upload URLs via the same bounded
// source resolver already used in the existing real ZIP suite.
$expected_source_plans = [];
foreach (['legacy' => [$a['form_id'], $a['entry_ids']],
          'single' => [$b['form_id'], $bids],
          'composite' => [$c['form_id'], $cids]] as $label => [$form_id, $ids]) {
    $plan = Download::plan((int)$form_id, $ids);
    $expected_source_plans[$label] = array_map(static function(array $p): array {
        return ['source' => $p['source'], 'sha' => $p['sha256'], 'size' => $p['size']];
    }, $plan);
    foreach ($plan as $p) { $source_before[$p['source']] = hash_file('sha256', $p['source']); }
}

// Register the actual Settings API sanitizer, not a local duplicate.
\GFBulkRename\Settings_Page::register_settings();
$save = static function(array $payload) use ($option): void {
    update_option($option, $payload);
};
$composite_input = static function(int $form_id, string $separator): array {
    return ['_form_id' => (string)$form_id, '_method' => 'composite',
        '_include' => ['6' => '1', '7' => '1'],
        '_field_order' => ['7' => '1', '6' => '2'], '_separator' => $separator];
};
$save(['_form_id' => (string)$a['form_id'], '_method' => 'legacy']);
$save(['_form_id' => (string)$b['form_id'], '_method' => 'single', '_single_field_id' => '6']);
$save(['_form_id' => (string)$c['form_id'], '_method' => 'composite',
    '_include' => ['6' => '1', '7' => '1'],
    '_field_order' => ['7' => '1', '6' => '2'], '_separator' => '__']);
$rules = get_option($option);
if (count($rules) !== 3 || $rules[(string)$c['form_id']]['field_ids'] !== [7, 6] ||
    $rules[(string)$b['form_id']]['field_id'] !== 6 ||
    get_option('gf_bulk_rename_options') !== $legacy_before) {
    throw new RuntimeException('First-save/double-sanitize or independent form rule failure');
}
// Direct update_option supplies an already-normalized value. Sanitizer must
// reject invalid separators without a second unslash or validator bypass.
foreach (['A\\B', '\\', '/'] as $invalid_separator) {
    $unchanged = get_option($option);
    $errors_before = count(get_settings_errors($option));
    $save($composite_input((int)$c['form_id'], $invalid_separator));
    if (get_option($option) !== $unchanged ||
        count(get_settings_errors($option)) <= $errors_before) {
        throw new RuntimeException('Direct invalid separator silently changed saved rules: ' .
            wp_json_encode($invalid_separator));
    }
}
foreach (['AB', '__', 'نام'] as $valid_separator) {
    $save($composite_input((int)$c['form_id'], $valid_separator));
    $actual = get_option($option);
    if ($actual[(string)$c['form_id']]['separator'] !== $valid_separator ||
        $actual[(string)$b['form_id']]['field_id'] !== 6 ||
        get_option('gf_bulk_rename_options') !== $legacy_before) {
        throw new RuntimeException('Direct valid separator was not preserved exactly');
    }
}
// Restore the existing canonical Composite fixture for ZIP checks below.
$save($composite_input((int)$c['form_id'], '__'));
echo 'PASS_REAL_WP_GF_DIRECT_SEPARATOR_REJECT_BACKSLASH_SLASH_PRESERVE_VALID' . PHP_EOL;

$before_invalid = get_option($option);
$save(['_form_id' => (string)$b['form_id'], '_method' => 'single', '_single_field_id' => '999']);
$save(['_form_id' => (string)$c['form_id'], '_method' => 'composite',
    '_include' => ['6' => '1', '7' => '1'],
    '_field_order' => ['6' => '1', '7' => '1'], '_separator' => '/']);
if (get_option($option) !== $before_invalid) { throw new RuntimeException('Invalid save damaged existing rules'); }
$save(['_form_id' => (string)$b['form_id'], '_method' => 'legacy']);
if (get_option($option)[(string)$c['form_id']] !== $rules[(string)$c['form_id']]) {
    throw new RuntimeException('Edit of Form B damaged Form C');
}
$save(['_form_id' => (string)$b['form_id'], '_method' => 'single', '_single_field_id' => '6']);
$plan_b = Download::plan((int)$b['form_id'], $bids);
if (count($plan_b) !== 2 || $plan_b[0]['name'] !== 'AB.jpg' ||
    !str_starts_with($plan_b[1]['name'], 'AB-') ||
    $plan_b[1]['name'] === $plan_b[0]['name']) {
    throw new RuntimeException('Single field collision/sanitization failed');
}
$plan_c = Download::plan((int)$c['form_id'], $cids);
if (count($plan_c) !== 6 || !str_starts_with($plan_c[0]['name'], '0__شیراز.') ||
    count(array_unique(array_column($plan_c, 'name'))) !== 6) {
    throw new RuntimeException('Composite zero/Unicode/order/collision failed');
}
try {
    Download::plan((int)$c['form_id'], [$invalid_id]);
    throw new RuntimeException('Missing naming field incorrectly allowed ZIP');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'Naming field')) { throw $e; }
}
$zip = Download::write_verified_zip($plan_c);
Download::verify_closed_zip($zip, $plan_c);
unlink($zip);
$save(['_form_id' => (string)$c['form_id'], '_method' => 'inherit']);
if (isset(get_option($option)[(string)$c['form_id']])) {
    throw new RuntimeException('Form override cannot be removed');
}
// WordPress can invoke sanitize_option twice for an option's first save.
// Both the rejection path and accepted Unicode must be stable across it.
delete_option($option);
$errors_before = count(get_settings_errors($option));
$save($composite_input((int)$c['form_id'], 'A\\B'));
if (get_option($option, []) !== [] || count(get_settings_errors($option)) <= $errors_before) {
    throw new RuntimeException('First-save invalid Composite rule was silently accepted');
}
$first_save_calls = 0;
$counter_callback = static function($value) use (&$first_save_calls) {
    ++$first_save_calls;
    return $value;
};
add_filter('sanitize_option_' . $option, $counter_callback, PHP_INT_MAX);
$save($composite_input((int)$c['form_id'], 'نام'));
remove_filter('sanitize_option_' . $option, $counter_callback, PHP_INT_MAX);
if ($first_save_calls < 2 ||
    (get_option($option)[(string)$c['form_id']]['separator'] ?? null) !== 'نام') {
    throw new RuntimeException('First-save double-sanitizer did not preserve valid Unicode separator');
}
delete_option($option);
echo 'PASS_REAL_WP_GF_FIRST_SAVE_DOUBLE_SANITIZER_SEPARATOR_GUARD' . PHP_EOL;
if (get_option('gf_bulk_rename_options') !== $legacy_before ||
    Download::plan((int)$a['form_id'], $a['entry_ids'])[0]['name'] !== 'd125.jpg') {
    throw new RuntimeException('Rollback to no rules changed Legacy behavior');
}
\GFBulkRename\Settings_Page::register_settings();
if (get_option($option) !== [] || get_option('gf_bulk_rename_options') !== $legacy_before) {
    throw new RuntimeException('Repeated settings initialization is destructive');
}
foreach ($source_before as $path => $sha) {
    if (hash_file('sha256', $path) !== $sha) { throw new RuntimeException('Source changed in CI rule test'); }
}
foreach ($entry_before as $id => $entry) {
    if (GFAPI::get_entry((int)$id) !== $entry) { throw new RuntimeException('GF Entry mutated by rule test'); }
}
file_put_contents($root . '/per-form.json', wp_json_encode([
    'a' => ['form_id' => (int)$a['form_id'], 'entry_ids' => $a['entry_ids']],
    'b' => ['form_id' => (int)$b['form_id'], 'entry_ids' => $bids],
    'c' => ['form_id' => (int)$c['form_id'], 'entry_ids' => $cids],
    'invalid_id' => $invalid_id, 'entries_before' => $entry_before,
    'source_before' => $source_before,
    'expected_sources' => $expected_source_plans,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo 'PASS_REAL_WP_GF_EXT001_PERSISTENCE_LEGACY_ISOLATION_COLLISION_UNICODE_ROLLBACK' . PHP_EOL;
