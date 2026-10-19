<?php
/** Strict, reversible filename candidate evaluator for the independent ZIP only. */
namespace GFBulkRename;
if (!defined('ABSPATH')) { exit; }

final class Naming_Strategy {
    public static function candidate(array $rule, array $entry, string $source): string {
        $ids = $rule['method'] === 'single' ? [$rule['field_id']] : $rule['field_ids'];
        $parts = [];
        foreach ($ids as $id) {
            $value = $entry[(string)$id] ?? null;
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                throw new \RuntimeException('Naming field ' . $id . ' is missing or not scalar for Entry ' . ($entry['id'] ?? '?'));
            }
            $text = preg_replace('/^\\s+|\\s+$/u', '', (string)$value);
            if ($text === null || $text === '') {
                throw new \RuntimeException('Naming field ' . $id . ' is empty or invalid for Entry ' . ($entry['id'] ?? '?'));
            }
            $parts[] = $text; // "0" is an ordinary numeric value in NEW strategies.
        }
        $stem = implode($rule['method'] === 'composite' ? $rule['separator'] : '', $parts);
        if (strlen($stem) > 180) {
            throw new \RuntimeException('Generated filename is too long');
        }
        $ext = pathinfo($source, PATHINFO_EXTENSION);
        $candidate = sanitize_file_name($stem . ($ext === '' ? '' : '.' . $ext));
        if ($candidate === '' || $candidate === '.' || $candidate === '..' ||
            str_starts_with($candidate, '.') ||
            str_contains($candidate, '/') || str_contains($candidate, '\\') ||
            preg_match('/[\\x00-\\x1f\\x7f]/', $candidate) ||
            strlen($candidate) > 240) {
            throw new \RuntimeException('Naming fields produce an unsafe filename');
        }
        return $candidate;
    }
}
