<?php
/** Reverify synthetic + original GF entries/uploads, Legacy and rules after HTTP. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('Real WP/GF required'); }
use GFBulkRename\Form_Naming_Rules as Rules;
use GFBulkRename\Flat_Zip_Download as Download;
$root = getenv('BRC_LAB_DIR');
$state = json_decode(file_get_contents($root . '/windows-names.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ([$state['entries'], $state['original_entries']] as $snapshots) {
    foreach ($snapshots as $id => $entry) {
        if (GFAPI::get_entry((int)$id) !== $entry) {
            throw new RuntimeException('GF Entry changed during Windows HTTP qualification: ' . $id);
        }
    }
}
foreach ($state['source_hashes'] as $path => $sha) {
    if (hash_file('sha256', $path) !== $sha) {
        throw new RuntimeException('Original upload changed during Windows HTTP qualification');
    }
}
if (get_option('gf_bulk_rename_options') !== $state['legacy_before']) {
    throw new RuntimeException('Legacy configuration changed during Windows qualification');
}
$cases = $state['cases'];
$bform = $cases['single_CONSOLE']['form_id'];
$cform = $cases['composite_CON_txt']['form_id'];
$rules = get_option(Rules::OPTION_NAME);
if (($rules[(string)$bform] ?? null) !== $state['previous_rules'][(string)$bform] ||
    ($rules[(string)$cform] ?? null) !==
        ['method'=>'composite','field_ids'=>[8,6],'separator'=>'.']) {
    throw new RuntimeException('Windows qualification changed unrelated per-form settings');
}
// WP-CLI runs outside admin_init. Explicitly register the same native sanitizer.
\GFBulkRename\Settings_Page::register_settings();
// Explicitly restore the previous C rule using the same registered sanitizer.
update_option(Rules::OPTION_NAME, ['_form_id' => (string)$cform, '_method'=>'composite',
    '_include'=>['7'=>'1','6'=>'1'], '_field_order'=>['7'=>'1','6'=>'2'],
    '_separator'=>'__']);
if (get_option(Rules::OPTION_NAME) !== $state['previous_rules']) {
    throw new RuntimeException('Original per-form settings not restored after qualification');
}
$baseline = json_decode(file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR)['scenarios']['single'];
if (Download::plan((int)$baseline['form_id'], $baseline['entry_ids'])[0]['name'] !== 'd125.jpg') {
    throw new RuntimeException('Legacy naming changed during device-name qualification');
}
echo 'PASS_WINDOWS_HTTP_GF_ENTRIES_SOURCES_LEGACY_AND_RULE_ISOLATION' . PHP_EOL;
