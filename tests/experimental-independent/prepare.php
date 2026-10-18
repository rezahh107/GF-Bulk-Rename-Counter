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
foreach (['multiple','shared','sanitized_equal','multi_entry_files','host_folder_collision'] as $name) {
    $s = $manifest['scenarios'][$name];
    $cases[$name] = ['form_id' => $s['form_id'], 'entry_ids' => $s['entry_ids']];
}
$add_case = function(string $name, array $values, $deleted = null) use (&$cases, $form_id): int {
    $id = GFAPI::add_entry(['form_id' => $form_id] + $values);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    if ($deleted !== null) { gform_update_meta((int)$id, 'bdfgf_deleted_file_urls', $deleted); }
    $cases[$name] = ['form_id' => $form_id, 'entry_ids' => [(int)$id]];
    return (int)$id;
};
$url = $first['1'];
$add_case('zero_counter', ['1' => $url, '5' => '0']);
$add_case('empty_counter', ['1' => $url, '5' => '']);
$add_case('repeated_physical', ['1' => $url, '2' => wp_json_encode([$url, $url]), '5' => '125']);
$add_case('previously_deleted', ['1' => $url, '2' => wp_json_encode([$url]), '5' => '125'], [$url]);
$add_case('all_deleted', ['1' => $url, '5' => '125'], [$url]);
$missing = str_replace('/single-a.jpg', '/does-not-exist.jpg', $url);
if ($missing === $url) { throw new RuntimeException('Missing fixture malformed'); }
$add_case('missing_mixed', ['1' => $url, '2' => wp_json_encode([$missing]), '5' => '125']);
$add_case('traversal', ['1' => dirname($url) . '/%2e%2e/secret.jpg', '5' => '125']);
$add_case('remote_url', ['1' => 'https://example.invalid/private.jpg', '5' => '125']);
$meta = ['cases' => $cases, 'threshold' => apply_filters('gform_bulk_action_threshold', 100)];
file_put_contents($root . '/independent.json', wp_json_encode($meta, JSON_PRETTY_PRINT));
echo 'Independent test selections: ' . count($all) . '; threshold=' . $meta['threshold'] . PHP_EOL;
$native = apply_filters('gform_entry_list_bulk_actions', ['gf_bulk_download' => 'Native bulk download'], $form_id);
if (!isset($native['gf_bulk_download'], $native[GFBRC_Independent_Flat_Zip_Experiment::ACTION])) {
    throw new RuntimeException('Custom entry-list action did not coexist with native action');
}
echo 'Both entry-list actions present: PASS' . PHP_EOL;
