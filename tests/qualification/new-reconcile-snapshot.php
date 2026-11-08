<?php
/** Isolated, read-only GFAPI/physical-source snapshot for P0 NEW reconciliation.
 * WP-CLI eval-file only, in the existing disposable PR #50 lab. Not a plugin hook.
 */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Real WordPress/Gravity Forms is required');
}
$lab = getenv('BRC_LAB_DIR');
if (!is_string($lab) || !is_dir($lab) || !str_starts_with(realpath($lab), '/tmp/brc-lab')) {
    throw new RuntimeException('Refusing to run outside disposable lab');
}
$fixture = json_decode(file_get_contents($lab . '/new-real-form-meta.json'), true, 512, JSON_THROW_ON_ERROR);
$requested = getenv('BRC_RECONCILE_FORM');
if (!is_string($requested) || !isset($fixture[$requested]['form'])) {
    throw new RuntimeException('Only PR50 synthetic form identities are permitted');
}
$form_id = (int)$fixture[$requested]['form'];
const BRC_RECONCILE_LIMIT = 40;

function brc_reconcile_rows(int $form): ?array {
    $out = [];
    foreach (['active', 'spam', 'trash'] as $status) {
        $count = 0;
        $part = GFAPI::get_entries($form, ['status' => $status],
            ['key' => 'id', 'direction' => 'ASC', 'is_numeric' => true],
            ['offset' => 0, 'page_size' => BRC_RECONCILE_LIMIT + 1], $count);
        if (!is_array($part) || count($part) !== (int)$count ||
            count($part) + count($out) > BRC_RECONCILE_LIMIT) {
            return null;
        }
        foreach ($part as $entry) {
            if ((int)$entry['form_id'] !== $form || ($entry['status'] ?? '') !== $status) {
                return null;
            }
            $out[(int)$entry['id']] = $entry;
        }
    }
    ksort($out, SORT_NUMERIC);
    return array_values($out);
}

function brc_reconcile_state(int $form): array {
    global $wpdb;
    $raw = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        'gfbrc_new_coverage_' . $form
    ));
    return ['raw' => $raw, 'decoded' => is_string($raw) ? json_decode($raw, true) :
        ['schema' => 1, 'rev' => 0, 'known' => [], 'covered' => []]];
}

function brc_reconcile_source(int $form, string $url): array {
    if ($url === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $url) || str_contains(rawurldecode($url), '..')) {
        return ['source_status' => 'invalid_ref'];
    }
    $wp = wp_upload_dir();
    $roots = [
        [(string)GFFormsModel::get_upload_url($form), (string)GFFormsModel::get_upload_path($form)],
        [(string)$wp['baseurl'], (string)$wp['basedir']],
    ];
    $match = false;
    foreach ($roots as [$baseurl, $basedir]) {
        $prefix = rtrim($baseurl, '/') . '/';
        if ($prefix === '/' || !str_starts_with(rawurldecode($url), $prefix)) { continue; }
        $match = true;
        $rel = substr(rawurldecode($url), strlen($prefix));
        $root = realpath($basedir);
        $file = $root && $rel !== '' ? realpath($root . '/' . $rel) : false;
        if ($file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) &&
            is_file($file) && is_readable($file)) {
            $size = filesize($file);
            $hash = hash_file('sha256', $file);
            if ($size !== false && is_string($hash)) {
                return ['source_status' => 'ok', 'size' => $size, 'sha256' => $hash];
            }
        }
    }
    return ['source_status' => $match ? 'missing_or_inaccessible_local' : 'unsupported_remote'];
}

$before = brc_reconcile_rows($form_id);
$coverage = brc_reconcile_state($form_id);
// Read the ACTUAL authoritative NEW operation, not a second planner-created
// candidate list. This is diagnostic inspection only, never a seed or ACK.
global $wpdb;
$pending_raw = $wpdb->get_var($wpdb->prepare(
    "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
    'gfbrc_new_pending_' . $form_id
));
$pending = is_string($pending_raw) ? json_decode($pending_raw, true) : null;
$active_pending = is_array($pending) &&
    in_array($pending['status'] ?? '', ['prepared', 'ready'], true);
$pending_ids = $active_pending && is_array($pending['ids'] ?? null) ? $pending['ids'] : [];
$pending_coherent = !$active_pending || (is_string($coverage['raw']) &&
    hash_equals((string)($pending['base'] ?? ''), hash('sha256', $coverage['raw'])) &&
    is_int($pending['expires'] ?? null) && $pending['expires'] >= time());
if ($before === null) {
    echo wp_json_encode(['form_id' => $form_id, 'scope' => ['complete' => false,
        'consistent' => false, 'total' => 0, 'entry_limit' => BRC_RECONCILE_LIMIT]]);
    return;
}
$form = GFAPI::get_form($form_id);
if (!is_array($form)) { throw new RuntimeException('Synthetic form unavailable'); }
$uploads = [];
foreach ($form['fields'] as $field) {
    if (($field->type ?? '') === 'fileupload') { $uploads[] = (string)$field->id; }
}
$entries = [];
$candidate_ids = [];
$known = is_array($coverage['decoded']['known'] ?? null) ? $coverage['decoded']['known'] : [];
foreach ($before as $entry) {
    $entry_id = (int)$entry['id'];
    $is_known = false;
    foreach ($known as $range) {
        if (is_array($range) && count($range) === 2 && $entry_id >= $range[0] && $entry_id <= $range[1]) {
            $is_known = true; break;
        }
    }
    if ($entry['status'] === 'active' && !$is_known) { $candidate_ids[] = $entry_id; }
    $deleted = maybe_unserialize(gform_get_meta($entry_id, 'bdfgf_deleted_file_urls'));
    $deleted = is_array($deleted) ? array_fill_keys(array_filter($deleted, 'is_string'), true) : [];
    $occurrences = [];
    foreach ($uploads as $field_id) {
        $raw = $entry[$field_id] ?? '';
        if ($raw === '') { continue; }
        if (!is_string($raw)) {
            $occurrences[] = ['field_id' => $field_id, 'slot' => 0, 'url' => '',
                'source_status' => 'invalid_ref'];
            continue;
        }
        $urls = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) { $urls = [$raw]; }
        if (!is_array($urls) || array_values($urls) !== $urls) {
            $occurrences[] = ['field_id' => $field_id, 'slot' => 0, 'url' => '',
                'source_status' => 'invalid_json'];
            continue;
        }
        foreach ($urls as $slot => $url) {
            $valid_url = is_string($url) ? $url : '';
            $occurrences[] = array_merge(['field_id' => $field_id, 'slot' => $slot,
                'url' => $valid_url, 'deleted' => isset($deleted[$valid_url])],
                brc_reconcile_source($form_id, $valid_url));
        }
    }
    $entries[] = ['entry_id' => $entry_id, 'status' => $entry['status'],
        'occurrences' => $occurrences];
}
$selected = [];
$planner_error = null;
if ($pending_ids) {
    try {
        $plan = \GFBulkRename\Flat_Zip_Download::plan($form_id, $pending_ids);
        foreach ($plan as $item) {
            $selected[] = ['entry_id' => (int)$item['entry_id'],
                'field_id' => (string)$item['field_id'], 'url' => (string)$item['url'],
                'name' => (string)$item['name'], 'size' => (int)$item['size'],
                'sha256' => (string)$item['sha256']];
        }
    } catch (\GFBulkRename\No_Eligible_Files_Exception $exception) {
        // An authentic zero-file candidate is allowed.
    } catch (Throwable $exception) {
        $planner_error = get_class($exception);
    }
}
$after = brc_reconcile_rows($form_id);
$coverage_after = brc_reconcile_state($form_id);
$pending_after = $wpdb->get_var($wpdb->prepare(
    "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
    'gfbrc_new_pending_' . $form_id
));
echo wp_json_encode(['form_id' => $form_id, 'entries' => $entries,
    'state' => $coverage['decoded'], 'selected' => $selected,
    'candidate_ids' => $candidate_ids,
    'pending' => ['status' => is_array($pending) ? ($pending['status'] ?? 'invalid') : 'absent',
        'ids' => $pending_ids, 'active' => $active_pending, 'coherent' => $pending_coherent],
    'planner_error' => $planner_error,
    'scope' => ['complete' => true, 'consistent' => $after === $before &&
        $coverage_after['raw'] === $coverage['raw'] && $pending_after === $pending_raw,
        'entry_limit' => BRC_RECONCILE_LIMIT, 'total' => count($before),
        'basis' => 'GFAPI active/spam/trash bounded read; physical local bytes',
        'atomic' => false]]);
