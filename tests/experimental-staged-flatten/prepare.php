<?php
/** Authentic WP-CLI fixture extension, post-baseline, disposable CI only. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Authentic WordPress/GF required');
}
$location = getenv('BRC_LAB_DIR') . '/manifest.json';
$manifest = json_decode(file_get_contents($location), true, 512, JSON_THROW_ON_ERROR);
$form_id = $manifest['scenarios']['multiple']['form_id'];
$made = static function (string $name, string $token): array {
    $source = wp_upload_bits($name, null, "stage-flat|$token|unique bytes");
    if ($source['error'] || !is_file($source['file'])) {
        throw new RuntimeException('Fixture upload failed');
    }
    return [
        'url' => $source['url'], 'path' => $source['file'],
        'sha256' => hash_file('sha256', $source['file']),
        'size' => filesize($source['file']),
    ];
};
$put = static function (string $key, array $entry_values, array $uploads,
    array $exclude = [], array $deleted = []) use (&$manifest, $form_id): void {
    $id = GFAPI::add_entry(['form_id' => $form_id] + $entry_values);
    if (is_wp_error($id) || !$id) {
        throw new RuntimeException('Cannot create synthetic entry');
    }
    if ($deleted) {
        gform_update_meta((int)$id, 'bdfgf_deleted_file_urls', $deleted);
    }
    $persisted = GFAPI::get_entry((int)$id);
    foreach ($entry_values as $field => $expected) {
        if (($persisted[(string)$field] ?? null) !== $expected) {
            throw new RuntimeException('Synthetic GF values not preserved');
        }
    }
    $manifest['scenarios'][$key] = [
        'form_id' => $form_id,
        'entry_ids' => [(int)$id],
        'entry_values' => [(string)$id => $entry_values],
        'uploads' => $uploads, 'excluded_uploads' => $exclude,
        'deleted_urls' => $deleted,
        'expected' => [],
    ];
};
$same = $made('mc-shared-physical.jpg', 'same-physical');
$put('sf_duplicate_physical', [
    '5' => '0', '1' => $same['url'],
    '2' => wp_json_encode([$same['url']]),
], [$same, $same]);
$valid = $made('mc-kept.jpg', 'kept');
$deleted = $made('mc-deleted.jpg', 'deleted');
$put('sf_deleted_exclusion', [
    '5' => '0', '1' => $valid['url'],
    '2' => wp_json_encode([$deleted['url']]),
], [$valid], [$deleted], [$deleted['url']]);

$missing = wp_upload_dir()['baseurl'] . '/2025/01/no-such-stage-flat-file.jpg';
$put('sf_missing_source', ['5' => '0', '1' => $missing], []);
$put('sf_invalid_source', ['5' => '0', '1' => 'https://untrusted.invalid/invalid.jpg'], []);
$unreadable = $made('mc-no-read.jpg', 'unreadable');
$put('sf_unreadable_source', ['5' => '0', '1' => $unreadable['url']], [$unreadable]);
$manifest['unreadable_fixture'] = $unreadable;
// Case-insensitive root collision between two eligible files in one Entry.
$case_a = $made('SF-CASE.jpg', 'upper-case');
$case_b = $made('sf-case.jpg', 'lower-case');
$put('sf_casefold', [
    '5' => '0', '1' => '', '2' => wp_json_encode([$case_a['url'], $case_b['url']]),
], [$case_a, $case_b]);

// Bounded performance fixture: native GF Entry and 50 original uploaded files,
// 64 KiB each; verify integrity using streaming digests.
$bulk = [];
for ($i = 0; $i < 50; $i++) {
    $bytes = str_repeat(chr(65 + $i % 26), 65536) . ':' . $i;
    $u = wp_upload_bits(sprintf('sf-perf-%03d.bin', $i), null, $bytes);
    if (!empty($u['error']) || !is_file($u['file'])) {
        throw new RuntimeException('SF_PERF_UPLOAD_FAILED');
    }
    $bulk[] = [
        'url' => $u['url'], 'path' => $u['file'],
        'sha256' => hash_file('sha256', $u['file']), 'size' => filesize($u['file']),
    ];
}
$put('sf_perf_50', [
    '5' => '0', '1' => '',
    '2' => wp_json_encode(array_column($bulk, 'url')),
], $bulk);

file_put_contents($location, wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Staged-flat authentic fixture cases provisioned\n";
