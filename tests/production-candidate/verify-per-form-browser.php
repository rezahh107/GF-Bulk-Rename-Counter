<?php
/** Verify real Chromium ZIP files against original GF uploads and saved rule order. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('WP/GF required'); }
$root = getenv('BRC_LAB_DIR');
$data = json_decode(file_get_contents($root . '/per-form.json'), true, 512, JSON_THROW_ON_ERROR);
$rules = get_option(\GFBulkRename\Form_Naming_Rules::OPTION_NAME);
if (count($rules) !== 2 ||
    $rules[(string)$data['b']['form_id']] !== ['method' => 'single', 'field_id' => 6] ||
    $rules[(string)$data['c']['form_id']] !==
        ['method' => 'composite', 'field_ids' => [7, 6], 'separator' => '__']) {
    throw new RuntimeException('Browser Settings API did not persist isolated final rules');
}
foreach (['b' => 2, 'c' => 6] as $key => $expected) {
    $case = $data[$key];
    $plan = \GFBulkRename\Flat_Zip_Download::plan($case['form_id'], $case['entry_ids']);
    if (count($plan) !== $expected) { throw new RuntimeException('Unexpected source occurrence count'); }
    try {
        \GFBulkRename\Flat_Zip_Download::verify_closed_zip($root . '/per-form-' . $key . '.zip', $plan);
    } catch (Throwable $e) {
        $probe = new ZipArchive();
        $observed = [];
        if ($probe->open($root . '/per-form-' . $key . '.zip') === true) {
            for ($index = 0; $index < $probe->numFiles; $index++) {
                $observed[] = $probe->getNameIndex($index);
            }
            $probe->close();
        }
        throw new RuntimeException('Actual downloaded archive differs in ' . $key .
            ': expected=' . wp_json_encode(array_column($plan, 'name'), JSON_UNESCAPED_UNICODE) .
            ' actual=' . wp_json_encode($observed, JSON_UNESCAPED_UNICODE) .
            '; prior=' . $e->getMessage(), 0, $e);
    }
    $z = new ZipArchive();
    if ($z->open($root . '/per-form-' . $key . '.zip') !== true ||
        $z->numFiles !== $expected) { throw new RuntimeException('Browser archive unreadable'); }
    $names = [];
    foreach ($plan as $index => $p) {
        $name = $z->getNameIndex($index);
        if ($name !== $p['name'] || str_contains($name, '/') ||
            str_contains($name, '\\') || isset($names[strtolower($name)])) {
            throw new RuntimeException('ZIP path/order/collision mismatch');
        }
        $names[strtolower($name)] = true;
    }
    $z->close();
    if ($key === 'c' && !str_starts_with($plan[0]['name'], '0__شیراز.')) {
        throw new RuntimeException('Browser Composite order/zero/Unicode not conserved');
    }
    echo 'PASS_AUTHENTIC_BROWSER_EXT001_ZIP_SOURCE_SHA256_COUNT_PATHS_' . strtoupper($key) . PHP_EOL;
}
foreach ($data['source_before'] as $path => $sha) {
    if (hash_file('sha256', $path) !== $sha) { throw new RuntimeException('Original upload changed'); }
}
foreach ($data['entries_before'] as $id => $entry) {
    if (GFAPI::get_entry((int)$id) !== $entry) { throw new RuntimeException('Original GF Entry changed'); }
}
if (get_option('gf_bulk_rename_options') !== ['form_id' => 0, 'counter_field_id' => 5]) {
    throw new RuntimeException('Legacy configuration was mutated');
}
$plan_a = \GFBulkRename\Flat_Zip_Download::plan($data['a']['form_id'], $data['a']['entry_ids']);
if ($plan_a[0]['name'] !== 'd125.jpg') { throw new RuntimeException('Unaffected Legacy form renamed'); }
echo 'PASS_EXT001_LEGACY_AND_SOURCE_ENTRY_IMMUTABILITY' . PHP_EOL;
