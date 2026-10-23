<?php
/** Real WordPress/GF synthetic entries for the independent-action CI experiment. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('Real WP/GF required'); }
$root = getenv('BRC_LAB_DIR');
$manifest = json_decode(file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$single = $manifest['scenarios']['single'];
$form_id = (int)$single['form_id'];
$first_id = (int)$single['entry_ids'][0];
$first = GFAPI::get_entry($first_id);
$all = [$first_id];
for ($n = 1; $n < 101; $n++) {
    $id = GFAPI::add_entry(['form_id' => $form_id, '1' => $first['1'], '5' => '125']);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $all[] = (int)$id;
}
$cases = [
    'one' => ['form_id' => $form_id, 'entry_ids' => array_slice($all, 0, 1)],
    'n99' => ['form_id' => $form_id, 'entry_ids' => array_slice($all, 0, 99)],
    'n100' => ['form_id' => $form_id, 'entry_ids' => array_slice($all, 0, 100)],
    'n101' => ['form_id' => $form_id, 'entry_ids' => array_slice($all, 0, 101)],
];
// Separate 154-Entry form exercises actual native pagination, scoped
// selection, zero-file Entries and repeated physical source occurrences.
$global_form = GFAPI::add_form([
    'title' => 'GFBRC global selection 154',
    'fields' => [
        ['id' => 1, 'type' => 'fileupload', 'label' => 'Single'],
        ['id' => 2, 'type' => 'fileupload', 'label' => 'Multiple', 'multipleFiles' => true],
        ['id' => 5, 'type' => 'text', 'label' => 'Group'],
    ],
    'button' => ['type' => 'text', 'text' => 'Submit'],
]);
if (is_wp_error($global_form)) { throw new RuntimeException('Global form creation failed'); }
$global_ids = []; $a_ids = []; $b_ids = [];
for ($i = 0; $i < 154; $i++) {
    $group = $i < 77 ? 'group-a' : 'group-b';
    $values = ['form_id' => (int)$global_form, '5' => $group];
    if ($i > 0) { $values['1'] = $first['1']; }
    if ($i === 1) { $values['2'] = wp_json_encode([$first['1'], $first['1']]); }
    $id = GFAPI::add_entry($values);
    if (is_wp_error($id)) { throw new RuntimeException('Global Entry insertion failed'); }
    $global_ids[] = (int)$id;
    if ($group === 'group-a') { $a_ids[] = (int)$id; }
    else { $b_ids[] = (int)$id; }
}
$cases['global154'] = ['form_id' => (int)$global_form, 'entry_ids' => $global_ids];
$cases['global_filtered'] = ['form_id' => (int)$global_form, 'entry_ids' => $a_ids];
$cases['global_group_b'] = ['form_id' => (int)$global_form, 'entry_ids' => $b_ids];
// All-matching Empty cannot produce an archive or count as a successful ZIP.
$empty_form = GFAPI::add_form([
    'title' => 'GFBRC global zero files',
    'fields' => [['id' => 1, 'type' => 'fileupload', 'label' => 'Single']],
    'button' => ['type' => 'text', 'text' => 'Submit'],
]);
if (is_wp_error($empty_form)) { throw new RuntimeException('No-file form unavailable'); }
$empty_ids = [];
for ($i = 0; $i < 3; $i++) {
    $id = GFAPI::add_entry(['form_id' => (int)$empty_form]);
    if (is_wp_error($id)) { throw new RuntimeException('No-file entry unavailable'); }
    $empty_ids[] = (int)$id;
}
$cases['global_empty'] = ['form_id' => (int)$empty_form, 'entry_ids' => $empty_ids];

foreach (['multiple','shared','sanitized_equal','multi_entry_files','host_folder_collision','custom_folder_mixed','entry_folder_mixed_multi'] as $name) {
    $s = $manifest['scenarios'][$name];
    $cases[$name] = ['form_id' => $s['form_id'], 'entry_ids' => $s['entry_ids']];
}
$add_case = function(string $name, array $values, $deleted = null) use (&$cases, $form_id): int {
    $id = GFAPI::add_entry(['form_id' => $form_id] + $values);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    if ($deleted !== null) {
        gform_update_meta((int)$id, 'bdfgf_deleted_file_urls', $deleted);
        gform_update_meta((int)$id, 'bdfgf_files_deleted', count($deleted) > 0);
    }
    $cases[$name] = ['form_id' => $form_id, 'entry_ids' => [(int)$id]];
    return (int)$id;
};
$url = $first['1'];
$add_case('zero_counter', ['1' => $url, '5' => '0']);
$add_case('empty_counter', ['1' => $url, '5' => '']);
$add_case('missing_counter', ['1' => $url]);
$add_case('malformed_counter', ['1' => $url, '5' => 'A?B']);
$add_case('repeated_physical', ['1' => $url, '2' => wp_json_encode([$url, $url]), '5' => '125']);
// Reserve a naturally occurring d125-<second Entry ID>.jpg before
// assigning a suffix to the second d125.jpg occurrence.
$reserved_first = GFAPI::add_entry(['form_id' => $form_id, '1' => $url, '5' => '125']);
$reserved_second = GFAPI::add_entry(['form_id' => $form_id, '1' => $url, '5' => '125']);
if (is_wp_error($reserved_first) || is_wp_error($reserved_second)) {
    throw new RuntimeException('Cannot prepare reserved-name collision Entries');
}
$reserved_third = GFAPI::add_entry([
    'form_id' => $form_id, '1' => $url, '5' => '125-' . $reserved_second
]);
if (is_wp_error($reserved_third)) { throw new RuntimeException('Cannot prepare natural name Entry'); }
$cases['natural_reserved'] = ['form_id' => $form_id,
    'entry_ids' => [(int)$reserved_first, (int)$reserved_second, (int)$reserved_third]];
$case_first = GFAPI::add_entry(['form_id' => $form_id, '1' => $url, '5' => 'AB']);
$case_second = GFAPI::add_entry(['form_id' => $form_id, '1' => $url, '5' => 'ab']);
if (is_wp_error($case_first) || is_wp_error($case_second)) {
    throw new RuntimeException('Cannot prepare case-insensitive filename collision Entries');
}
$cases['casefold_collision'] = ['form_id' => $form_id,
    'entry_ids' => [(int)$case_first, (int)$case_second]];

$other = $manifest['scenarios']['shared']['uploads'][0]['url'];
$add_case('previously_deleted', ['1' => $url, '2' => wp_json_encode([$other]), '5' => '125'], [$url]);
$add_case('all_deleted', ['1' => $url, '5' => '125'], [$url]);
$missing = str_replace('/single-a.jpg', '/does-not-exist.jpg', $url);
if ($missing === $url) { throw new RuntimeException('Missing fixture malformed'); }
$add_case('missing_mixed', ['1' => $url, '2' => wp_json_encode([$missing]), '5' => '125']);
$add_case('traversal', ['1' => dirname($url) . '/%2e%2e/secret.jpg', '5' => '125']);
$add_case('remote_url', ['1' => 'https://example.invalid/private.jpg', '5' => '125']);

// An inconsistent pair of host-owned metadata keys must fail closed instead
// of reintroducing previously deleted uploads into a downloadable ZIP.
$flag_only_id = $add_case('deleted_flag_without_urls', ['1' => $url, '5' => '125']);
gform_update_meta($flag_only_id, 'bdfgf_files_deleted', true);

// Observable malformed/contradictory host metadata must fail, with no ZIP.
$bad_list_id = $add_case('malformed_deleted_list', ['1' => $url, '5' => '125']);
gform_update_meta($bad_list_id, 'bdfgf_deleted_file_urls', ['invalid' => $url]);
gform_update_meta($bad_list_id, 'bdfgf_files_deleted', true);
$bad_flag_id = $add_case('unrecognized_deleted_flag', ['1' => $url, '5' => '125']);
gform_update_meta($bad_flag_id, 'bdfgf_files_deleted', 'deleted-in-future-schema');
$list_only_id = $add_case('deleted_list_without_flag', ['1' => $url, '5' => '125']);
gform_update_meta($list_only_id, 'bdfgf_deleted_file_urls', [$url]);
$unknown_id = $add_case('unknown_deleted_url', ['1' => $url, '5' => '125']);
gform_update_meta($unknown_id, 'bdfgf_files_deleted', true);
gform_update_meta($unknown_id, 'bdfgf_deleted_file_urls', [$missing]);

// Exercise the *installed unmodified host's actual delete path* with a brand
// new CI-owned upload and Entry. This does not touch original fixture files.
$host_delete_upload = wp_upload_bits('ifz-host-deleted-' . wp_generate_password(12, false) . '.jpg',
    null, 'synthetic-file-to-be-deleted-only-in-CI');
if (!empty($host_delete_upload['error']) || !is_file($host_delete_upload['file'])) {
    throw new RuntimeException('Cannot prepare host deletion-only synthetic upload');
}
$host_deleted_id = GFAPI::add_entry(['form_id' => $form_id, '1' => $host_delete_upload['url'], '5' => '125']);
if (is_wp_error($host_deleted_id)) { throw new RuntimeException('Cannot add host deletion-only Entry'); }
$original_form = GFAPI::get_form($form_id);
$delete_form = $original_form;
$delete_form['bulkDownloadSettings']['customDeleteEntryFiles'] = true;
try {
    $updated = GFAPI::update_form($delete_form);
    if (is_wp_error($updated)) { throw new RuntimeException('Host deletion setting not writable'); }
    if (!class_exists('\\BDFGF\\Actions\\BulkDelete')) {
        throw new RuntimeException('Pinned authentic host delete class missing');
    }
    (new \BDFGF\Actions\BulkDelete())->bulk_delete($form_id, [(int)$host_deleted_id]);
} finally {
    $restored = GFAPI::update_form($original_form);
    if (is_wp_error($restored)) { throw new RuntimeException('Cannot restore form settings after CI deletion'); }
}
clearstatcache(true, $host_delete_upload['file']);
$host_urls = maybe_unserialize(gform_get_meta((int)$host_deleted_id, 'bdfgf_deleted_file_urls'));
if (file_exists($host_delete_upload['file']) ||
    !gform_get_meta((int)$host_deleted_id, 'bdfgf_files_deleted') ||
    !is_array($host_urls) || !in_array($host_delete_upload['url'], $host_urls, true)) {
    throw new RuntimeException('Actual host delete did not remove file and mark exact URL');
}
$cases['actual_host_deleted'] = ['form_id' => $form_id, 'entry_ids' => [(int)$host_deleted_id]];
echo 'PASS_AUTHENTIC_BULK_DOWNLOAD_DELETION_META_AND_REMOVED_SOURCE entry=' . $host_deleted_id . PHP_EOL;

// Real filesystem unreadability: the synthetic test upload is CI-only.
$unreadable = wp_upload_bits('independent-unreadable.jpg', null, 'only-in-ci-unreadable');
if (!empty($unreadable['error']) || !is_file($unreadable['file'])) {
    throw new RuntimeException('Unable to create unreadable fixture: ' . ($unreadable['error'] ?? 'missing file'));
}
$add_case('unreadable', ['1' => $unreadable['url'], '5' => '125']);
if (!chmod($unreadable['file'], 0000) || is_readable($unreadable['file'])) {
    throw new RuntimeException('CI cannot enforce unreadable source-file fixture');
}
// Valid but foreign form entry IDs must never leak through form-scoped POST.
$cases['cross_form'] = [
    'form_id' => $form_id,
    'entry_ids' => $manifest['scenarios']['multiple']['entry_ids'],
];
$cases['duplicate_selected_id'] = ['form_id' => $form_id, 'entry_ids' => [$first_id, $first_id]];
// Representative workload: distinct physical files; source bytes need not be
// held in an aggregate in-memory array by the writer.
$large_urls = [];
for ($i = 0; $i < 24; $i++) {
    $uploaded = wp_upload_bits(sprintf('large-%03d.jpg', $i), null, random_bytes(262144));
    if (!empty($uploaded['error']) || !is_file($uploaded['file'])) {
        throw new RuntimeException('Unable to provision representative large upload');
    }
    $large_urls[] = $uploaded['url'];
}
$add_case('larger_workload', ['2' => wp_json_encode($large_urls), '5' => '125']);
$meta_snapshots = [];
foreach ($cases as $name => $scenario) {
    $meta_snapshots[$name] = [];
    foreach (array_unique($scenario['entry_ids']) as $id) {
        $entry = GFAPI::get_entry((int)$id);
        if (is_wp_error($entry) || !is_array($entry)) {
            throw new RuntimeException('Snapshot source Entry unavailable');
        }
        $meta_snapshots[$name][(string)$id] = [
            'form_id' => (int)$entry['form_id'],
            '1' => $entry['1'] ?? null,
            '2' => $entry['2'] ?? null,
            '5' => $entry['5'] ?? null,
            'deleted' => gform_get_meta((int)$id, 'bdfgf_deleted_file_urls'),
            'deleted_flag' => (bool) gform_get_meta((int)$id, 'bdfgf_files_deleted'),
        ];
    }
}

$meta = ['cases' => $cases, 'threshold' => apply_filters('gform_bulk_action_threshold', 100)];
file_put_contents($root . '/independent.json', wp_json_encode($meta, JSON_PRETTY_PRINT));
file_put_contents($root . '/independent-snapshots.json', wp_json_encode($meta_snapshots, JSON_PRETTY_PRINT));
file_put_contents($root . '/independent-unreadable-path.txt', $unreadable['file']);
echo 'Independent test selections: ' . count($all) . '; threshold=' . $meta['threshold'] . PHP_EOL;
$native = apply_filters('gform_entry_list_bulk_actions', ['gf_bulk_download' => 'Native bulk download'], $form_id);
if (!isset($native['gf_bulk_download'], $native[\GFBulkRename\Flat_Zip_Download::ACTION])) {
    echo 'DIAGNOSTIC user=' . get_current_user_id() . ' wp_cap=' . (int) current_user_can('gravityforms_view_entries') .
        ' gf_cap=' . (int) GFCommon::current_user_can_any('gravityforms_view_entries') .
        ' registered=' . (int) has_filter('gform_entry_list_bulk_actions', [\GFBulkRename\Flat_Zip_Download::class, 'add_action']) .
        ' actual_keys=' . implode(',', array_keys($native)) . PHP_EOL;
    throw new RuntimeException('Custom entry-list action did not coexist with native action');
}
echo 'Both entry-list actions present: PASS' . PHP_EOL;
