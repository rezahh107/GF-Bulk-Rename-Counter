<?php
/**
 * Per-form naming rules. Separate from the original, host-dependent Legacy option.
 * Stored rules are keyed by Gravity Form ID; a settings submission edits one key.
 */
namespace GFBulkRename;
if (!defined('ABSPATH')) { exit; }

final class Form_Naming_Rules {
    public const OPTION_NAME = 'gf_bulk_rename_per_form_rules';
    public const OPTION_GROUP = 'gf_bulk_rename_per_form_settings';

    /** Only scalar, non-compound fields have a single stable Entry key. */
    public static function eligible_fields(array $form): array {
        $fields = [];
        foreach (($form['fields'] ?? []) as $field) {
            if (!is_object($field) || !in_array(($field->type ?? ''), ['text', 'textarea', 'number'], true)) {
                continue;
            }
            $id = (int)($field->id ?? 0);
            if ($id > 0 && (string)$id === (string)($field->id ?? '')) {
                $fields[$id] = (string)($field->label ?? ('Field ' . $id));
            }
        }
        return $fields;
    }

    private static function validate_saved(array $form, $rule): array {
        if (!is_array($rule) || !isset($rule['method']) || !is_string($rule['method'])) {
            throw new \RuntimeException('Invalid saved naming rule');
        }
        $method = $rule['method'];
        if ($method === 'legacy') {
            return ['method' => 'legacy'];
        }
        $allowed = self::eligible_fields($form);
        if ($method === 'single') {
            $field_id = $rule['field_id'] ?? null;
            if (!is_int($field_id) || !isset($allowed[$field_id])) {
                throw new \RuntimeException('Selected Single Field is no longer valid');
            }
            return ['method' => 'single', 'field_id' => $field_id];
        }
        if ($method !== 'composite' || !isset($rule['field_ids'], $rule['separator']) ||
            !is_array($rule['field_ids']) || !is_string($rule['separator'])) {
            throw new \RuntimeException('Invalid saved Composite rule');
        }
        $ids = $rule['field_ids'];
        if (array_values($ids) !== $ids || count($ids) < 2 ||
            count($ids) > count($allowed) || count(array_unique($ids)) !== count($ids)) {
            throw new \RuntimeException('Composite field order is invalid');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || !isset($allowed[$id])) {
                throw new \RuntimeException('Composite field no longer exists or is ineligible');
            }
        }
        self::validate_separator($rule['separator']);
        return ['method' => 'composite', 'field_ids' => $ids, 'separator' => $rule['separator']];
    }

    private static function validate_separator(string $separator): void {
        if (strlen($separator) > 32 || !preg_match('//u', $separator) ||
            strpbrk($separator, '/\\\\') !== false || preg_match('/[\\x00-\\x1f\\x7f]/', $separator)) {
            throw new \RuntimeException('Separator must be valid text without slashes or control characters (max 32 bytes)');
        }
    }

    /** Invalid persisted rules fail at the ZIP preparation boundary. */
    public static function for_form(array $form): ?array {
        $all = get_option(self::OPTION_NAME, []);
        if (!is_array($all)) { throw new \RuntimeException('Invalid saved per-form rules'); }
        $id = (string)(int)($form['id'] ?? 0);
        if (!array_key_exists($id, $all)) { return null; }
        return self::validate_saved($form, $all[$id]);
    }

    public static function sanitize($input): array {
        // WordPress may sanitize twice while first creating an option. Only a
        // normalized value produced by THIS request can pass the second call.
        static $previous_normalized = null;
        if (is_array($previous_normalized) && $input === $previous_normalized) {
            return $previous_normalized;
        }
        $old = get_option(self::OPTION_NAME, []);
        if (!is_array($old)) { $old = []; }
        try {
            if (!is_array($input) || !isset($input['_form_id'], $input['_method']) ||
                !is_scalar($input['_form_id']) || !is_string($input['_method'])) {
                throw new \RuntimeException('Invalid form rule submission');
            }
            $id = absint($input['_form_id']);
            if ($id < 1 || (string)$id !== (string)$input['_form_id']) {
                throw new \RuntimeException('Select a valid Gravity Form');
            }
            $form = \GFAPI::get_form($id);
            if (!is_array($form)) { throw new \RuntimeException('Form no longer exists'); }
            $method = $input['_method'];
            if ($method === 'inherit') {
                unset($old[(string)$id]);
                $previous_normalized = $old;
                return $old;
            }
            if ($method === 'legacy') {
                $rule = ['method' => 'legacy'];
            } elseif ($method === 'single') {
                $field_id = $input['_single_field_id'] ?? null;
                $field_id = is_scalar($field_id) ? (string)$field_id : '';
                if (!ctype_digit($field_id) || (int)$field_id < 1) {
                    throw new \RuntimeException('Choose a Single Field');
                }
                $rule = ['method' => 'single', 'field_id' => (int)$field_id];
            } elseif ($method === 'composite') {
                $chosen = $input['_include'] ?? [];
                $positions = $input['_field_order'] ?? [];
                if (!is_array($chosen) || !is_array($positions) || count($chosen) < 2) {
                    throw new \RuntimeException('Select at least two Composite fields');
                }
                $ranked = [];
                foreach ($chosen as $field_id => $checked) {
                    $fid = (string)$field_id;
                    $rank = $positions[$field_id] ?? null;
                    if (!ctype_digit($fid) || !is_scalar($rank) ||
                        !ctype_digit((string)$rank) || (int)$rank < 1 ||
                        (int)$rank > count($chosen) || (string)$checked !== '1' ||
                        isset($ranked[(int)$rank])) {
                        throw new \RuntimeException('Composite fields need unique consecutive order numbers');
                    }
                    $ranked[(int)$rank] = (int)$fid;
                }
                ksort($ranked, SORT_NUMERIC);
                if (array_keys($ranked) !== range(1, count($ranked))) {
                    throw new \RuntimeException('Composite order must start at 1 without gaps');
                }
                $separator = $input['_separator'] ?? null;
                if (!is_string($separator)) { throw new \RuntimeException('Invalid separator'); }
                $rule = ['method' => 'composite', 'field_ids' => array_values($ranked),
                    'separator' => $separator];
            } else {
                throw new \RuntimeException('Unknown naming method');
            }
            $old[(string)$id] = self::validate_saved($form, $rule);
            $previous_normalized = $old;
            return $old;
        } catch (\Throwable $error) {
            add_settings_error(self::OPTION_NAME, 'invalid_form_rule',
                sprintf(__('Naming rule not saved: %s', 'gf-bulk-rename-counter'), $error->getMessage()), 'error');
            return $old; // Non-destructive rejection; do not alter any other form.
        }
    }
}
