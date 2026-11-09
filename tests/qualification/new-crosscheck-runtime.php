<?php
/** Authentic isolated WP/GF cross-check; fault injections live only in memory. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Authentic WordPress/GF runtime required');
}
$lab = getenv('BRC_LAB_DIR');
$root = is_string($lab) ? realpath($lab) : false;
if (!$root || !str_starts_with($root, '/tmp/brc-lab')) {
    throw new RuntimeException('Only disposable lab allowed');
}
$fixture = json_decode(file_get_contents($root . '/new-real-form-meta.json'), true, 512, JSON_THROW_ON_ERROR);
$name = getenv('BRC_CROSSCHECK_CASE');
if (!in_array($name, ['optional','multi'], true) || !isset($fixture[$name]['form'])) {
    throw new RuntimeException('Unknown synthetic fixture');
}
$form = (int)$fixture[$name]['form'];
global $wpdb;
$coverage_before = $wpdb->get_var($wpdb->prepare(
    "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
    'gfbrc_new_coverage_' . $form
));
$pending_before = $wpdb->get_var($wpdb->prepare(
    "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
    'gfbrc_new_pending_' . $form
));
$coverage = json_decode($coverage_before, true, 512, JSON_THROW_ON_ERROR);
$pending = json_decode($pending_before, true, 512, JSON_THROW_ON_ERROR);
if (!in_array($pending['status'] ?? '', ['prepared','ready'], true) ||
    !is_array($pending['ids'] ?? null) || !$pending['ids']) {
    throw new RuntimeException('Authentic NEW prepared generation missing');
}
$table = GFFormsModel::get_entry_table_name();
if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
    throw new RuntimeException('Unsafe GF Entry table');
}
if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false ||
    $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT') === false) {
    throw new RuntimeException('Consistent source view unavailable');
}
$in_tx = true;
try {
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id,status FROM {$table} WHERE form_id=%d ORDER BY id ASC LIMIT 41", $form
    ), ARRAY_A);
    if (!is_array($rows) || count($rows) >= 41) {
        throw new RuntimeException('Bounded synthetic population unavailable');
    }
    $observed = \GFBulkRename\New_Identity_Crosscheck::observe($form, $rows,
        $coverage['known'], $coverage['covered']);
    if (!$observed['complete']) {
        throw new RuntimeException('Real independent GF observation unavailable');
    }
    if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Read-only COMMIT failed'); }
    $in_tx = false;
} finally {
    if ($in_tx) { $wpdb->query('ROLLBACK'); }
}
$before = \GFBulkRename\New_Identity_Crosscheck::sample_sources($form, $observed);
try {
    $plan = \GFBulkRename\Flat_Zip_Download::plan($form, $pending['ids']);
} catch (\GFBulkRename\No_Eligible_Files_Exception $e) {
    $plan = [];
}
$after = \GFBulkRename\New_Identity_Crosscheck::sample_sources($form, $observed);
$stable = \GFBulkRename\New_Identity_Crosscheck::stable_entries($form, $observed);
$good = \GFBulkRename\New_Identity_Crosscheck::evaluate($observed,
    $pending['ids'], $plan, $before, $after, $stable);
if ($good['classification'] !== 'NO_OBSERVED_MISMATCH' ||
    $good['eligible_entries'] !== count($pending['ids']) ||
    $good['eligible_files'] !== count($plan)) {
    throw new RuntimeException('Authentic normal control failed: ' . wp_json_encode($good));
}
if ($name === 'optional') {
    if (count($pending['ids']) !== 1 || count($plan) !== 0) {
        throw new RuntimeException('Real zero-file fixture invalid');
    }
    $reduced_ids = [];
    $negative = \GFBulkRename\New_Identity_Crosscheck::evaluate($observed,
        $reduced_ids, $plan, $before, $after, $stable);
    $reason = 'eligible_entry_missing_from_NEW_selection';
} else {
    if (count($plan) !== 3) { throw new RuntimeException('Real multi-file fixture invalid'); }
    $reduced_plan = array_slice($plan, 0, -1);
    $negative = \GFBulkRename\New_Identity_Crosscheck::evaluate($observed,
        $pending['ids'], $reduced_plan, $before, $after, $stable);
    $reason = 'eligible_file_occurrence_missing_from_NEW_plan';
}
if ($negative['classification'] !== 'CONFIRMED_MISMATCH' ||
    !in_array($reason, array_column($negative['discrepancies'], 'reason'), true)) {
    throw new RuntimeException('Real independent omission not detected: ' . wp_json_encode($negative));
}
// Synthetic-only form metadata mutations: the checked production diagnostic
// itself never writes. Use the genuine GFAPI form read/update/read cycle, and
// restore the isolated form in finally even when a discriminator fails.
if ($name === 'multi') {
    $original_form = GFAPI::get_form($form);
    if (!is_array($original_form) || !is_array($original_form['fields'] ?? null)) {
        throw new RuntimeException('Cannot capture synthetic form metadata');
    }
    $original_uploads = array_column($observed['upload_schema'], 'id');
    if (count($original_uploads) !== 3) {
        throw new RuntimeException('Multi fixture must contain three genuine upload fields');
    }
    $target_id = max($original_uploads);
    $all_field_ids = array_map(static fn($field) => (int)$field->id, $original_form['fields']);
    $new_id = max($all_field_ids) + 1;
    $baseline_entries = [];
    foreach ($pending['ids'] as $entry_id) {
        $baseline_entries[$entry_id] = GFAPI::get_entry($entry_id);
    }
    $assert_form_fields = static function (array $expected) use ($form): void {
        $actual = GFAPI::get_form($form);
        if (!is_array($actual) || !is_array($actual['fields'] ?? null)) {
            throw new RuntimeException('GF source form definition unreadable after synthetic change');
        }
        $ids = [];
        foreach ($actual['fields'] as $field) {
            if (($field->type ?? null) === 'fileupload') { $ids[] = (int)$field->id; }
        }
        sort($ids, SORT_NUMERIC);
        sort($expected, SORT_NUMERIC);
        if ($ids !== $expected) {
            throw new RuntimeException('GF form schema change not visible through real GFAPI');
        }
    };
    $restore_form = static function () use ($original_form, $assert_form_fields, $original_uploads): void {
        if (GFAPI::update_form($original_form) !== true) {
            throw new RuntimeException('Synthetic GF form schema could not be restored');
        }
        $assert_form_fields($original_uploads);
    };
    try {
        foreach (['remove', 'change_type', 'add'] as $case) {
            $candidate = $original_form;
            $candidate['fields'] = array_map(static fn($field) => clone $field, $original_form['fields']);
            if ($case === 'remove') {
                $candidate['fields'] = array_values(array_filter($candidate['fields'],
                    static fn($field) => (int)$field->id !== $target_id));
                $expected_uploads = array_values(array_diff($original_uploads, [$target_id]));
            } elseif ($case === 'change_type') {
                foreach ($candidate['fields'] as $field) {
                    if ((int)$field->id === $target_id) { $field->type = 'text'; }
                }
                $expected_uploads = array_values(array_diff($original_uploads, [$target_id]));
            } else {
                $copy = null;
                foreach ($candidate['fields'] as $field) {
                    if ((int)$field->id === $target_id) { $copy = clone $field; }
                }
                if (!is_object($copy)) { throw new RuntimeException('Cannot clone synthetic GF field'); }
                $copy->id = $new_id;
                $copy->label = 'Synthetic schema guard field';
                $candidate['fields'][] = $copy;
                $expected_uploads = array_merge($original_uploads, [$new_id]);
            }

            // First check is deliberately BEFORE the metadata changes.
            $pre_plan_consistent = \GFBulkRename\New_Identity_Crosscheck::stable_upload_schema(
                $form, $observed);
            if (!$pre_plan_consistent) {
                throw new RuntimeException('Normal form schema unexpectedly unstable');
            }
            if (GFAPI::update_form($candidate) !== true) {
                throw new RuntimeException('Failed to mutate synthetic GF form schema');
            }
            $assert_form_fields($expected_uploads);
            $schema_changed_before_plan = \GFBulkRename\New_Identity_Crosscheck::stable_upload_schema(
                $form, $observed);
            if ($schema_changed_before_plan) {
                throw new RuntimeException('Pre-plan schema guard missed ' . $case);
            }
            try {
                $changed_plan = \GFBulkRename\Flat_Zip_Download::plan($form, $pending['ids']);
            } catch (\GFBulkRename\No_Eligible_Files_Exception $e) {
                $changed_plan = [];
            }
            $source_after_schema = \GFBulkRename\New_Identity_Crosscheck::sample_sources(
                $form, $observed);
            $entry_schema_stable = \GFBulkRename\New_Identity_Crosscheck::stable_entries(
                $form, $observed);
            if ($entry_schema_stable || $source_after_schema !== $before) {
                throw new RuntimeException('Expected independent source stability and changed schema: ' . $case);
            }
            $diagnosed = \GFBulkRename\New_Identity_Crosscheck::evaluate(
                $observed, $pending['ids'], $changed_plan,
                $before, $source_after_schema,
                $schema_changed_before_plan && $entry_schema_stable
            );
            if ($diagnosed['classification'] !== 'INCONCLUSIVE') {
                throw new RuntimeException('GF schema drift produced definitive diagnostic: ' .
                    $case . ' ' . wp_json_encode($diagnosed));
            }
            if ($case !== 'add') {
                // This negative control reproduces PRI-FND-001 on actual GF:
                // the internally plausible reduced plan alone WOULD be called
                // a confirmed omission if the schema check were bypassed.
                $without_guard = \GFBulkRename\New_Identity_Crosscheck::evaluate(
                    $observed, $pending['ids'], $changed_plan,
                    $before, $source_after_schema, true
                );
                if ($without_guard['classification'] !== 'CONFIRMED_MISMATCH') {
                    throw new RuntimeException('Negative control cannot falsify the missing guard');
                }
            }
            foreach ($baseline_entries as $entry_id => $original_entry) {
                if (GFAPI::get_entry($entry_id) !== $original_entry) {
                    throw new RuntimeException('Synthetic form schema test changed an Entry');
                }
            }
            echo 'PASS_REAL_GF_NEW_SCHEMA_' . strtoupper($case) .
                '_INCONCLUSIVE_WITH_UNCHANGED_ENTRY_AND_SOURCE' . PHP_EOL;
            $restore_form();
            if (!\GFBulkRename\New_Identity_Crosscheck::stable_entries($form, $observed)) {
                throw new RuntimeException('Schema restoration did not recover stable diagnosis');
            }
        }
    } finally {
        $restore_form();
    }
    echo 'PASS_REAL_GF_NEW_SCHEMA_NEGATIVE_CONTROL_AND_RESTORED_FORM' . PHP_EOL;
}
$coverage_after = $wpdb->get_var($wpdb->prepare(
    "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
    'gfbrc_new_coverage_' . $form
));
$pending_after = $wpdb->get_var($wpdb->prepare(
    "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
    'gfbrc_new_pending_' . $form
));
if ($coverage_before !== $coverage_after || $pending_before !== $pending_after) {
    throw new RuntimeException('Diagnostic unexpectedly changed ACK/coverage or pending');
}
echo 'PASS_REAL_GF_INDEPENDENT_NEW_' . strtoupper($name) . '_OMISSION_CONTROL' . PHP_EOL;
