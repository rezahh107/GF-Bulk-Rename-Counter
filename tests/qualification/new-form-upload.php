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
if ($action === 'state') {
    global $wpdb;
    $result = [];
    foreach (['required' => $required, 'optional' => $optional] as $name => $form) {
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            'gfbrc_new_coverage_' . $form));
        $result[$name] = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
    }
    echo wp_json_encode($result);
    return;
}
throw new RuntimeException('Unknown isolated P0 form action');