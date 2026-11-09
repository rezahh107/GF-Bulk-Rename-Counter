<?php
/** Read-only, bounded source-derived Check NEW diagnostics; never ACK authority. */
namespace GFBulkRename;

if (!defined('ABSPATH')) { exit; }

final class New_Identity_Crosscheck {
    private const MAX_OCCURRENCES = 10000;
    private const MAX_SCHEMA_FIELDS = 10000;
    private const MAX_SOURCE_BYTES = 134217728;

    /** Called inside NEW's existing REPEATABLE READ snapshot, not from its plan. */
    public static function observe(int $form, array $rows, array $known, array $covered): array {
        try {
            $expected = [];
            $excluded = 0;
            $highest = null;
            foreach ($rows as $row) {
                $id = self::identity($row['id'] ?? null);
                if ($id === null) { throw new \RuntimeException('invalid_stored_identity'); }
                $highest = $id;
                if (!in_array($row['status'] ?? null, ['active', 'spam', 'trash'], true)) {
                    throw new \RuntimeException('unknown_entry_status');
                }
                if ($row['status'] !== 'active' || self::in_ranges($known, $id)) {
                    ++$excluded;
                } else {
                    $expected[] = $id;
                }
            }
            $previous = $covered ? $covered[count($covered) - 1][1] : null;
            if (count($expected) > 500) { throw new \RuntimeException('bounded_candidate_limit'); }
            $upload_schema = self::upload_schema($form);
            if ($upload_schema === null) { throw new \RuntimeException('upload_schema_unavailable'); }
            $upload_fields = array_map(static fn($field) => (string)$field['id'], $upload_schema);
            $occurrences = [];
            $values = [];
            foreach ($expected as $id) {
                $entry = \GFAPI::get_entry($id);
                if (!is_array($entry) || self::identity($entry['id'] ?? null) !== $id ||
                    self::identity($entry['form_id'] ?? null) !== $form ||
                    ($entry['status'] ?? null) !== 'active') {
                    throw new \RuntimeException('entry_temporally_unavailable');
                }
                $deleted = self::deleted_urls($id);
                $seen_references = [];
                $values[$id] = [];
                foreach ($upload_fields as $field) {
                    $raw = $entry[$field] ?? '';
                    if (!is_string($raw)) { throw new \RuntimeException('invalid_upload_field'); }
                    $values[$id][$field] = $raw;
                    if ($raw === '') { continue; }
                    $urls = json_decode($raw, true);
                    if (json_last_error() !== JSON_ERROR_NONE) { $urls = [$raw]; }
                    if (!is_array($urls) || array_values($urls) !== $urls) {
                        throw new \RuntimeException('invalid_multifile_field');
                    }
                    foreach ($urls as $slot => $url) {
                        if (!is_string($url) || trim($url) === '') {
                            throw new \RuntimeException('invalid_upload_reference');
                        }
                        $seen_references[$url] = true;
                        $occurrences[] = ['entry_id' => $id, 'field_id' => $field,
                            'slot' => $slot, 'url' => $url, 'deleted' => isset($deleted[$url])];
                        if (count($occurrences) > self::MAX_OCCURRENCES) {
                            throw new \RuntimeException('bounded_occurrence_limit');
                        }
                    }
                }
                foreach ($deleted as $url => $_) {
                    if (!isset($seen_references[$url])) {
                        throw new \RuntimeException('deleted_reference_not_in_entry');
                    }
                }
                $values[$id]['__deleted'] = array_keys($deleted);
            }
            return ['complete' => true, 'ids' => $expected, 'occurrences' => $occurrences,
                'values' => $values, 'upload_schema' => $upload_schema,
                'excluded' => $excluded, 'highest' => $highest,
                'previous_covered_highest' => $previous];
        } catch (\Throwable $e) {
            // Source failures must not change original NEW selection or ACK.
            return ['complete' => false, 'reason' => 'independent_observation_unavailable'];
        }
    }

    private static function identity($raw): ?int {
        if (is_int($raw)) { return $raw > 0 ? $raw : null; }
        if (!is_string($raw) || !preg_match('/\A[1-9][0-9]*\z/D', $raw)) { return null; }
        $id = (int)$raw;
        return $id > 0 && (string)$id === $raw ? $id : null;
    }

    /** Canonical upload-field IDs/types from real GF metadata, never from NEW's plan. */
    private static function upload_schema(int $form): ?array {
        $definition = \GFAPI::get_form($form);
        if (!is_array($definition) || !isset($definition['fields']) ||
            !is_array($definition['fields']) ||
            count($definition['fields']) > self::MAX_SCHEMA_FIELDS) { return null; }
        $seen = [];
        $uploads = [];
        foreach ($definition['fields'] as $field) {
            if (!is_object($field) || !is_string($field->type ?? null) || $field->type === '') {
                return null;
            }
            $id = self::identity($field->id ?? null);
            if ($id === null || isset($seen[$id])) { return null; }
            $seen[$id] = true;
            if ($field->type === 'fileupload') {
                $uploads[] = ['id' => $id, 'type' => 'fileupload'];
            }
        }
        usort($uploads, static fn($a, $b) => $a['id'] <=> $b['id']);
        return $uploads;
    }

    /** Read again before/after the planner; invalid or changed metadata is unknown. */
    public static function stable_upload_schema(int $form, array $observation): bool {
        if (($observation['complete'] ?? false) !== true ||
            !is_array($observation['upload_schema'] ?? null)) { return false; }
        try {
            $current = self::upload_schema($form);
            return $current !== null && $current === $observation['upload_schema'];
        } catch (\Throwable $e) { return false; }
    }

    private static function in_ranges(array $ranges, int $id): bool {
        // Coverage ranges may contain 50,000 isolated IDs: never rescan
        // the entire history for each current Entry.
        $low = 0; $high = count($ranges) - 1;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($id < $ranges[$mid][0]) { $high = $mid - 1; }
            elseif ($id > $ranges[$mid][1]) { $low = $mid + 1; }
            else { return true; }
        }
        return false;
    }

    private static function deleted_urls(int $id): array {
        if (!function_exists('gform_get_meta')) { throw new \RuntimeException('meta_unavailable'); }
        $raw = maybe_unserialize(gform_get_meta($id, 'bdfgf_deleted_file_urls'));
        $flag = gform_get_meta($id, 'bdfgf_files_deleted');
        if (in_array($raw, [null, false, ''], true)) { $raw = []; }
        if (!is_array($raw) || array_values($raw) !== $raw) { throw new \RuntimeException('bad_deleted_urls'); }
        $set = [];
        foreach ($raw as $url) {
            if (!is_string($url) || $url === '' || isset($set[$url])) {
                throw new \RuntimeException('bad_deleted_url');
            }
            $set[$url] = true;
        }
        $active = in_array($flag, [true, 1, '1'], true);
        if (!in_array($flag, [true, 1, '1', false, null, '', 0, '0'], true) ||
            $active !== (count($set) > 0)) { throw new \RuntimeException('bad_deleted_flag'); }
        return $set;
    }

    /** Independent URL -> local bytes. Unsupported remote or unstable source is unknown. */
    private static function source(int $form, string $url, int $remaining): ?array {
        $decoded = rawurldecode($url);
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded)) { return null; }
        $parsed = wp_parse_url($decoded);
        if (!is_array($parsed) || !in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'], true) ||
            !empty($parsed['user']) || !empty($parsed['pass']) ||
            !empty($parsed['query']) || !empty($parsed['fragment'])) { return null; }
        $wp = wp_upload_dir();
        $roots = [[(string)$wp['baseurl'], (string)$wp['basedir']]];
        $gfu = \GFFormsModel::get_upload_url($form);
        $gfp = \GFFormsModel::get_upload_path($form);
        if (is_string($gfu) && is_string($gfp)) { $roots[] = [$gfu, $gfp]; }
        foreach ($roots as [$baseurl, $basedir]) {
            $base = wp_parse_url($baseurl);
            if (!is_array($base) || strcasecmp($base['scheme'] ?? '', $parsed['scheme'] ?? '') !== 0 ||
                strcasecmp($base['host'] ?? '', $parsed['host'] ?? '') !== 0 ||
                ($base['port'] ?? null) !== ($parsed['port'] ?? null)) { continue; }
            $prefix = rtrim($base['path'] ?? '', '/') . '/';
            if (!str_starts_with($parsed['path'] ?? '', $prefix)) { continue; }
            $relative = substr($parsed['path'], strlen($prefix));
            $segments = explode('/', $relative);
            if ($relative === '' || in_array('', $segments, true) ||
                in_array('.', $segments, true) || in_array('..', $segments, true)) { return null; }
            $root = realpath($basedir);
            $path = $root ? realpath($root . '/' . $relative) : false;
            if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) ||
                !is_file($path) || !is_readable($path)) { return null; }
            $size = filesize($path);
            if (!is_int($size) || $size > $remaining) { return null; }
            $sha = hash_file('sha256', $path);
            return is_string($sha) ? ['size' => $size, 'sha256' => $sha] : null;
        }
        return null;
    }

    public static function sample_sources(int $form, array $observation): array {
        $samples = [];
        if (($observation['complete'] ?? false) !== true) { return $samples; }
        clearstatcache(true);
        $remaining = self::MAX_SOURCE_BYTES;
        foreach ($observation['occurrences'] as $index => $item) {
            if ($item['deleted']) { continue; }
            $samples[$index] = $remaining > 0 ? self::source($form, $item['url'], $remaining) : null;
            if ($samples[$index] !== null) { $remaining -= $samples[$index]['size']; }
        }
        return $samples;
    }

    /** Confirm that the same GF fields/deletion set still exist after planning. */
    public static function stable_entries(int $form, array $observation): bool {
        if (($observation['complete'] ?? false) !== true) { return false; }
        try {
            foreach ($observation['values'] as $id => $fields) {
                $entry = \GFAPI::get_entry($id);
                if (!is_array($entry) || self::identity($entry['id'] ?? null) !== $id ||
                    self::identity($entry['form_id'] ?? null) !== $form ||
                    ($entry['status'] ?? null) !== 'active') { return false; }
                foreach ($fields as $field => $raw) {
                    if ($field === '__deleted') {
                        if ($raw !== array_keys(self::deleted_urls($id))) { return false; }
                    } elseif (($entry[$field] ?? '') !== $raw) { return false; }
                }
            }
            return self::stable_upload_schema($form, $observation);
        } catch (\Throwable $e) { return false; }
    }

    /** Pure comparison of independent identities/occurrences against actual NEW. */
    public static function evaluate(array $observation, array $new, array $plan,
        array $before, array $after, bool $entries_stable): array {
        $result = ['classification' => 'INCONCLUSIVE', 'eligible_entries' => 0,
            'eligible_files' => null, 'excluded_entries' => 0, 'excluded_files' => 0,
            'current_highest' => null, 'previous_covered_highest' => null,
            'discrepancies' => [], 'reason' => 'observation_incomplete'];
        if (($observation['complete'] ?? false) !== true) { return $result; }
        $ids = $observation['ids'] ?? [];
        $items = $observation['occurrences'] ?? [];
        if (!is_array($ids) || !is_array($items) || count($ids) > 500 ||
            count($items) > self::MAX_OCCURRENCES || !is_array($new) || !is_array($plan) ||
            !is_array($before) || !is_array($after)) { return $result; }
        $result['eligible_entries'] = count($ids);
        $result['excluded_entries'] = $observation['excluded'];
        $result['current_highest'] = $observation['highest'];
        $result['previous_covered_highest'] = $observation['previous_covered_highest'];
        if (!$entries_stable) { $result['reason'] = 'entry_or_status_changed'; return $result; }
        $expected_set = [];
        foreach ($ids as $id) {
            if (self::identity($id) !== $id || isset($expected_set[$id])) { return $result; }
            $expected_set[$id] = true;
        }
        $selected_set = [];
        foreach ($new as $id) {
            if (self::identity($id) !== $id || isset($selected_set[$id])) { return $result; }
            $selected_set[$id] = true;
        }
        $selected_occurrences = [];
        foreach ($plan as $item) {
            if (!is_array($item) || self::identity($item['entry_id'] ?? null) === null ||
                !is_string($item['url'] ?? null)) { return $result; }
            $key = wp_json_encode([$item['entry_id'], (string)$item['field_id'], $item['url']]);
            $selected_occurrences[$key][] = ['size' => $item['size'] ?? null,
                'sha256' => $item['sha256'] ?? null];
        }
        $unknown = false;
        $files = 0;
        foreach ($items as $index => $item) {
            if (!isset($expected_set[$item['entry_id'] ?? 0]) ||
                !is_string($item['field_id'] ?? null) || !is_string($item['url'] ?? null) ||
                !is_int($item['slot'] ?? null)) { return $result; }
            if ($item['deleted']) { ++$result['excluded_files']; continue; }
            ++$files;
            $a = $before[$index] ?? null; $b = $after[$index] ?? null;
            if (!is_array($a) || $a !== $b) { $unknown = true; continue; }
            $key = wp_json_encode([$item['entry_id'], $item['field_id'], $item['url']]);
            if (!empty($selected_occurrences[$key])) {
                $selected_file = array_pop($selected_occurrences[$key]);
                if ($selected_file !== $a) { $unknown = true; }
            } else {
                $result['discrepancies'][] = ['entry_id' => $item['entry_id'],
                    'field_id' => $item['field_id'], 'slot' => $item['slot'],
                    'reason' => 'eligible_file_occurrence_missing_from_NEW_plan'];
            }
        }
        $result['eligible_files'] = $files;
        foreach ($ids as $id) {
            if (!isset($selected_set[$id])) {
                $result['discrepancies'][] = ['entry_id' => $id,
                    'reason' => 'eligible_entry_missing_from_NEW_selection'];
            }
        }
        foreach ($selected_set as $id => $_) { if (!isset($expected_set[$id])) { $unknown = true; } }
        foreach ($selected_occurrences as $leftover) {
            if ($leftover) { $unknown = true; break; }
        }
        if ($unknown) {
            // Current sources or membership are not trustworthy: do not turn a
            // partial observation into a global PASS or a definitive omission.
            $result['classification'] = 'INCONCLUSIVE';
            $result['reason'] = 'source_or_selection_unstable';
        } elseif ($result['discrepancies']) {
            $result['classification'] = 'CONFIRMED_MISMATCH';
            $result['reason'] = 'independently_observed_omission';
        } elseif ($result['excluded_entries'] || $result['excluded_files']) {
            $result['classification'] = 'EXPECTED_EXCLUSION';
            $result['reason'] = 'contractual_exclusions_only';
        } else {
            $result['classification'] = 'NO_OBSERVED_MISMATCH';
            $result['reason'] = 'bounded_independent_scope_consistent';
        }
        $result['mismatch_count'] = count($result['discrepancies']);
        $result['discrepancies'] = array_slice($result['discrepancies'], 0, 10);
        return $result;
    }
}
