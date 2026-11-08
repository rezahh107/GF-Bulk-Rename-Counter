<?php
/**
 * Disposable P0 NEW qualification: genuine front-end GF upload + independent
 * persisted Entry/file oracle. Run only inside the isolated WordPress CI lab.
 * No production plugin code, stored real data, or NEW semantics are changed.
 */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Authentic WordPress/Gravity Forms required');
}
$lab = getenv('BRC_LAB_DIR');
if (!is_string($lab) || !is_dir($lab) || !str_starts_with(realpath($lab), '/tmp/brc-lab')) {
    throw new RuntimeException('Not the approved disposable lab');
}
$meta_file = $lab . '/new-real-form-meta.json';
$action = getenv('BRC_FORM_UPLOAD_ACTION');
function brc_p0_entries(int $form): array {
    $total = 0;
    $entries = GFAPI::get_entries($form, ['status' => 'active'],
        ['key' => 'id', 'direction' => 'ASC', 'is_numeric' => true],
        ['offset' => 0, 'page_size' => 10], $total);
    if (!is_array($entries) || count($entries) !== (int)$total || $total > 2) {
        throw new RuntimeException('Independent GFAPI population mismatch');
    }
    foreach ($entries as $entry) {
        if ((int)$entry['form_id'] !== $form || $entry['status'] !== 'active') {
            throw new RuntimeException('Wrong persisted form/status');
        }
    }
    return $entries;
}
function brc_p0_source(int $form, string $url): array {
    if ($url === '') { throw new RuntimeException('No persisted upload URL'); }
    $wp = wp_upload_dir();
    $roots = [
        [(string) GFFormsModel::get_upload_url($form), (string) GFFormsModel::get_upload_path($form)],
        [(string) $wp['baseurl'], (string) $wp['basedir']],
    ];
    $url = rawurldecode($url);
    $file = false;
    foreach ($roots as [$baseurl, $basedir]) {
        $prefix = rtrim($baseurl, '/') . '/';
        if (!str_starts_with($url, $prefix)) { continue; }
        $relative = substr($url, strlen($prefix));
        if ($relative === '' || str_contains($relative, '..') ||
            str_contains($relative, chr(92))) {
            throw new RuntimeException('Invalid GF stored upload path');
        }
        $root = realpath($basedir);
        $candidate = $root ? realpath($root . '/' . $relative) : false;
        if ($root && $candidate && str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) &&
            is_file($candidate) && is_readable($candidate)) {
            $file = $candidate;
            break;
        }
    }
    if (!$file) {
        throw new RuntimeException('Persisted GF upload unavailable in permitted local roots');
    }
    return ['url' => $url, 'sha256' => hash_file('sha256', $file),
        'size' => filesize($file), 'basename' => basename($file)];
}
if ($action === 'setup') {
    $result = [];
    foreach (['required' => true, 'optional' => false] as $name => $required) {
        $id = GFAPI::add_form([
            'title' => 'GFBRC P0 REAL WEB SUBMIT ' . $name,
            'fields' => [
                ['id' => 1, 'type' => 'fileupload', 'label' => 'Document',
                    'isRequired' => $required, 'allowedExtensions' => 'txt'],
                ['id' => 2, 'type' => 'text', 'label' => 'Fixture code', 'isRequired' => true],
            ],
            'button' => ['type' => 'text', 'text' => 'Submit'],
        ]);
        if (is_wp_error($id) || (int)$id < 1) {
            throw new RuntimeException('Could not create real form ' . $name);
        }
        $page = wp_insert_post([
            'post_type' => 'page', 'post_status' => 'publish',
            'post_title' => 'GFBRC isolated upload ' . $name,
            'post_content' => '[gravityform id="' . (int)$id . '" title="false" description="false" ajax="false"]'
        ], true);
        if (is_wp_error($page) || (int)$page < 1) {
            throw new RuntimeException('Could not publish isolated test page');
        }
        $result[$name] = ['form' => (int)$id, 'page' => get_permalink($page)];
    }
    file_put_contents($meta_file, wp_json_encode($result));
    // Genuine browser submission with three independently persisted upload
    // fields. Two files intentionally share original name and byte content.
    $multi = GFAPI::add_form([
        'title' => 'GFBRC P0 REAL MULTIFIELD SUBMISSION',
        'fields' => [
            ['id' => 1, 'type' => 'fileupload', 'label' => 'Unique document',
                'isRequired' => true, 'allowedExtensions' => 'txt'],
            ['id' => 2, 'type' => 'fileupload', 'label' => 'Equal document A',
                'isRequired' => true, 'allowedExtensions' => 'txt'],
            ['id' => 3, 'type' => 'fileupload', 'label' => 'Equal document B',
                'isRequired' => true, 'allowedExtensions' => 'txt'],
            ['id' => 4, 'type' => 'text', 'label' => 'Synthetic code', 'isRequired' => true],
        ],
        'button' => ['type' => 'text', 'text' => 'Submit'],
    ]);
    if (is_wp_error($multi) || (int)$multi < 1) {
        throw new RuntimeException('Could not create genuine multifield form');
    }
    $multi_page = wp_insert_post([
        'post_type' => 'page', 'post_status' => 'publish',
        'post_title' => 'GFBRC isolated multi-occurrence uploads',
        'post_content' => '[gravityform id="' . (int)$multi . '" title="false" description="false" ajax="false"]'
    ], true);
    if (is_wp_error($multi_page) || (int)$multi_page < 1) {
        throw new RuntimeException('Could not publish genuine multifield page');
    }
    $result['multi'] = ['form' => (int)$multi, 'page' => get_permalink($multi_page)];
    file_put_contents($meta_file, wp_json_encode($result));
    echo wp_json_encode($result);
    return;
}
$meta = json_decode(file_get_contents($meta_file), true, 512, JSON_THROW_ON_ERROR);
$required = (int)$meta['required']['form'];
$optional = (int)$meta['optional']['form'];
if ($action === 'read') {
    $yes = brc_p0_entries($required);
    $no = brc_p0_entries($optional);
    if (count($yes) !== 1 || count($no) > 1) {
        throw new RuntimeException('Unexpected front-end submission population');
    }
    $positive = $yes[0];
    $source = brc_p0_source($required, (string)($positive['1'] ?? ''));
    $optional_entry = $no ? $no[0] : null;
    echo wp_json_encode([
        'required' => ['id' => (int)$positive['id'], 'form' => $required,
            'value' => (string)$positive['1'], 'source' => $source],
        'optional' => $optional_entry ? ['id' => (int)$optional_entry['id'],
            'form' => $optional, 'value' => (string)($optional_entry['1'] ?? '')] : null,
    ]);
    return;
}
if ($action === 'attach') {
    $from = brc_p0_entries($required);
    $to = brc_p0_entries($optional);
    if (count($from) !== 1 || count($to) !== 1 || (string)($to[0]['1'] ?? '') !== '') {
        throw new RuntimeException('Late attach precondition violated');
    }
    $url = (string)$from[0]['1'];
    $ok = GFAPI::update_entry_field((int)$to[0]['id'], 1, $url);
    if (is_wp_error($ok) || $ok === false) {
        throw new RuntimeException('Actual GFAPI late upload-field update failed');
    }
    $again = GFAPI::get_entry((int)$to[0]['id']);
    if (!is_array($again) || (string)($again['1'] ?? '') !== $url) {
        throw new RuntimeException('Late field persistence mismatch');
    }
    echo wp_json_encode(['id' => (int)$again['id'],
        'source' => brc_p0_source($optional, $url)]);
    return;
}
if ($action === 'read_multi' || $action === 'partial_zip') {
    $form = (int)$meta['multi']['form'];
    $entries = brc_p0_entries($form);
    if (count($entries) !== 1) {
        throw new RuntimeException('Expected one real multifield browser submission');
    }
    $entry = $entries[0];
    // Test precondition: no form-specific or legacy naming changes this form.
    // The independent oracle derives expected names from GF source basenames.
    $rules = \GFBulkRename\Form_Naming_Rules::for_form(GFAPI::get_form($form));
    $options = get_option('gf_bulk_rename_options', []);
    if (!is_array($options)) { $options = []; }
    $counter_id = (int)($options['counter_field_id'] ?? 0);
    $counter_form = (int)($options['form_id'] ?? 0);
    if ($rules !== null || ($counter_id > 0 && (!$counter_form || $counter_form === $form))) {
        throw new RuntimeException('Multifield oracle requires a default-name synthetic form');
    }
    $files = [];
    foreach ([1, 2, 3] as $field) {
        $value = $entry[(string)$field] ?? '';
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Missing real browser upload field ' . $field);
        }
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) { $decoded = [$value]; }
        if (!is_array($decoded) || array_values($decoded) !== $decoded || count($decoded) !== 1) {
            throw new RuntimeException('Unexpected single-file GF metadata shape');
        }
        foreach ($decoded as $slot => $url) {
            if (!is_string($url) || $url === '') {
                throw new RuntimeException('Invalid stored upload occurrence');
            }
            $source = brc_p0_source($form, $url);
            $files[] = [
                'form_id' => $form, 'entry_id' => (int)$entry['id'],
                'field_id' => (string)$field, 'slot' => $slot, 'url' => $url,
                'basename' => $source['basename'], 'size' => $source['size'],
                'sha256' => $source['sha256'],
            ];
        }
    }
    if (count($files) !== 3 || $files[0]['sha256'] === $files[1]['sha256'] ||
        $files[1]['sha256'] !== $files[2]['sha256']) {
        throw new RuntimeException('Independent equal-content source population is invalid');
    }
    if ($action === 'read_multi') {
        echo wp_json_encode(['form_id' => $form, 'entry_id' => (int)$entry['id'], 'files' => $files]);
        return;
    }
    // Deliberately faulty *reduced planner* is independently verified against
    // itself, proving why comparing ZIP only to the planner is insufficient.
    $full_plan = \GFBulkRename\Flat_Zip_Download::plan($form, [(int)$entry['id']]);
    if (count($full_plan) !== 3) {
        throw new RuntimeException('Full plugin plan differs from real GF source occurrences');
    }
    $reduced = array_slice($full_plan, 0, 2);
    $temp = \GFBulkRename\Flat_Zip_Download::write_verified_zip($reduced);
    try {
        \GFBulkRename\Flat_Zip_Download::verify_closed_zip($temp, $reduced);
        $destination = $lab . '/new-real-incomplete-planner.zip';
        if (!copy($temp, $destination)) {
            throw new RuntimeException('Unable to stage deliberately reduced ZIP');
        }
    } finally {
        @unlink($temp);
    }
    echo wp_json_encode(['archive' => $destination, 'reduced_count' => 2,
        'full_source_count' => count($files), 'internally_verified' => true]);
    return;
}
if ($action === 'state') {
    global $wpdb;
    $result = [];
    foreach (['required' => $required, 'optional' => $optional, 'multi' => (int)$meta['multi']['form']] as $name => $form) {
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            'gfbrc_new_coverage_' . $form));
        $result[$name] = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
    }
    echo wp_json_encode($result);
    return;
}
throw new RuntimeException('Unknown isolated P0 form action');