<?php
/**
 * Authentic WordPress lifecycle regression, run only against a disposable CI site.
 * Phases are separate WP-CLI processes so plugin loading matches each installed version.
 */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('WordPress and Gravity Forms required');
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$phase = getenv('GFBRC_LIFECYCLE_PHASE');
$plugin = 'gf-bulk-rename-counter/gf-bulk-rename-counter.php';
$legacy = 'gf_bulk_rename_options';
$per_form = 'gf_bulk_rename_per_form_rules';
$other = 'gfbrc_ci_other_plugin_option'; // Test-only surrogate owned by another plugin.
$fixture = json_decode(file_get_contents(getenv('BRC_LAB_DIR') . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$form_id = (int)$fixture['scenarios']['single']['form_id'];
$new_fixture = getenv('BRC_LAB_DIR') . '/new-independent-form.json';
$new_form_id = is_file($new_fixture)
    ? (int)(json_decode(file_get_contents($new_fixture), true)['form'] ?? 0) : 0;
$new_key = $new_form_id ? 'gfbrc_new_coverage_' . $new_form_id : '';
$pending_key = $new_form_id ? 'gfbrc_new_pending_' . $new_form_id : '';

$assert_retained = static function () use ($legacy, $per_form, $other, $form_id, $new_key, $pending_key): void {
    if (get_option($legacy) !== ['form_id' => 0, 'counter_field_id' => 5]) {
        throw new RuntimeException('Legacy settings were lost or changed');
    }
    $rules = get_option($per_form);
    if (!is_array($rules) || ($rules[(string)$form_id] ?? null) !== ['method' => 'legacy']) {
        throw new RuntimeException('Per-form settings were lost or changed');
    }
    if ($new_key !== '' && !is_string(get_option($new_key))) {
        throw new RuntimeException('Acknowledged NEW coverage was lost during lifecycle');
    }
    if ($pending_key !== '' && !is_string(get_option($pending_key))) {
        throw new RuntimeException('Authoritative pending NEW row was lost during lifecycle');
    }
    if (get_option($other) !== ['keep' => 'other-plugin-data']) {
        throw new RuntimeException('Unrelated plugin option was modified');
    }
};

if ($phase === 'seed') {
    if (!is_plugin_active($plugin)) {
        throw new RuntimeException('Target must be active before seed');
    }
    \GFBulkRename\Settings_Page::register_settings();
    \GFBulkRename\Settings_Page::register_settings();
    update_option($legacy, ['form_id' => 0, 'counter_field_id' => 5]);
    delete_option($per_form);
    update_option($per_form, ['_form_id' => (string)$form_id, '_method' => 'legacy']);
    update_option($other, ['keep' => 'other-plugin-data']);
    $assert_retained();
    // An ordinary repeated save must not delete the other form's option.
    update_option($legacy, ['form_id' => 0, 'counter_field_id' => 5]);
    $assert_retained();
    echo 'PASS_WP_LIFECYCLE_REPEAT_INIT_AND_SEED' . PHP_EOL;
} elseif (in_array($phase, ['deactivated', 'reactivated', 'updated', 'rolled_back', 'restored'], true)) {
    $assert_retained();
    if (in_array($phase, ['reactivated', 'updated', 'rolled_back', 'restored'], true) && !is_plugin_active($plugin)) {
        throw new RuntimeException('Plugin not active after install/activation');
    }
    echo 'PASS_WP_LIFECYCLE_' . strtoupper($phase) . '_RETAINS_BOTH_OPTIONS' . PHP_EOL;
} elseif ($phase === 'uninstall') {
    if (is_plugin_active($plugin)) {
        throw new RuntimeException('Deactivate before explicit uninstall');
    }
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    uninstall_plugin($plugin); // Actual WordPress uninstall path, NOT a direct include.
    global $wpdb;
    foreach (array_filter([$legacy, $per_form, $new_key, $pending_key]) as $name) {
        $count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $name
        ));
        if ($count !== 0) {
            throw new RuntimeException('Uninstall left plugin-owned option: ' . $name);
        }
    }
    if (get_option($other) !== ['keep' => 'other-plugin-data']) {
        throw new RuntimeException('Uninstall touched another plugin option');
    }
    delete_option($other);
    echo 'PASS_WP_EXPLICIT_UNINSTALL_REMOVES_ONLY_GFBRC_OWNED_OPTIONS' . PHP_EOL;
} else {
    throw new RuntimeException('Unknown lifecycle phase');
}
