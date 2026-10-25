<?php
/** Authentic WP/GF tests for the read-only administration experience. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Real WordPress and Gravity Forms required');
}
use GFBulkRename\Admin_Experience as UX;
use GFBulkRename\Settings_Page as Settings;

$root = getenv('BRC_LAB_DIR');
$data = json_decode(file_get_contents($root . '/per-form.json'), true, 512, JSON_THROW_ON_ERROR);
$a = GFAPI::get_form((int)$data['a']['form_id']);
$b = GFAPI::get_form((int)$data['b']['form_id']);
$c = GFAPI::get_form((int)$data['c']['form_id']);
if (!is_array($a) || !is_array($b) || !is_array($c)) {
    throw new RuntimeException('Authentic forms unavailable');
}
$admin = get_user_by('login', 'bootstrap_admin');
wp_set_current_user((int)$admin->ID);
if (!current_user_can('manage_options')) { throw new RuntimeException('Admin capability missing'); }
$admin_links = UX::plugin_links([]);
$menu = UX::gf_toolbar([], $a['id']);
if (!str_contains(implode('', $admin_links), 'gf-bulk-rename-settings') ||
    !str_contains($menu['gfbrc_settings']['url'] ?? '', 'gfbrc_form=' . $a['id']) ||
    !str_contains(UX::settings_url((int)$a['id']), 'options-general.php?page=gf-bulk-rename-settings')) {
    throw new RuntimeException('Native administration navigation is not reachable');
}
$baseline = get_option(Settings::OPTION_NAME);
if ($baseline !== ['form_id' => 0, 'counter_field_id' => 5]) {
    throw new RuntimeException('Unexpected Legacy fixture');
}
$state = UX::status($a);
if ($state[0] !== 'ready' || !str_contains($state[1], 'Legacy') ||
    !str_contains($state[2], 'field 5')) {
    throw new RuntimeException('Inherited Legacy effective rule not explained');
}
// Test read-only incomplete and scoped-out states using real WP options filters.
// Do not persist changes to canonical fixture or run a download on invalid config.
add_filter('pre_option_' . Settings::OPTION_NAME, $off = static fn() =>
    ['form_id' => 0, 'counter_field_id' => 0]);
if (UX::status($a)[0] !== 'unchanged') { throw new RuntimeException('Missing Legacy counter not identified'); }
remove_filter('pre_option_' . Settings::OPTION_NAME, $off);
add_filter('pre_option_' . Settings::OPTION_NAME, $scoped = static fn() =>
    ['form_id' => (int)$b['id'], 'counter_field_id' => 5]);
if (UX::status($a)[0] !== 'unchanged') { throw new RuntimeException('Out-of-scope Legacy naming not identified'); }
remove_filter('pre_option_' . Settings::OPTION_NAME, $scoped);
add_filter('pre_option_' . Settings::OPTION_NAME, $invalid = static fn() =>
    ['form_id' => 0, 'counter_field_id' => 99999]);
if (UX::status($a)[0] !== 'warning') { throw new RuntimeException('Stale field not flagged'); }
remove_filter('pre_option_' . Settings::OPTION_NAME, $invalid);
if (get_option(Settings::OPTION_NAME) !== $baseline) {
    throw new RuntimeException('Read-only states mutated real settings');
}
$phase = getenv('BRC_UI_PHASE') ?: 'initial';
if ($phase === 'configured') {
    $single = UX::status($b);
    $composite = UX::status($c);
    if ($single[0] !== 'ready' || !str_contains($single[1], 'Single Field') ||
        !str_contains($single[2], 'Name') ||
        $composite[0] !== 'ready' || !str_contains($composite[1], 'Composite') ||
        !str_contains($composite[2], 'Number') ||
        !str_contains($composite[2], '__')) {
        throw new RuntimeException('Configured per-form effective rules not described');
    }
    echo 'PASS_REAL_WP_GF_CONFIGURED_SINGLE_COMPOSITE_READ_ONLY_RULES' . PHP_EOL;
    return;
}
// Separate operator may see download guidance but not admin configuration.
$role = add_role('gfbrc_ci_operator', 'GFBRC synthetic operator',
    ['read' => true, 'gravityforms_view_entries' => true]);
if (!$role) { $role = get_role('gfbrc_ci_operator'); }
$operator_id = wp_create_user('gfbrc_ui_operator', wp_generate_password(32),
    'gfbrc_ui_operator@example.invalid');
if (is_wp_error($operator_id)) { throw new RuntimeException('Operator creation failed'); }
$operator = new WP_User((int)$operator_id);
$operator->set_role('gfbrc_ci_operator');
wp_set_current_user((int)$operator_id);
if (current_user_can('manage_options') ||
    !\GFCommon::current_user_can_any('gravityforms_view_entries') ||
    UX::gf_toolbar([], $a['id']) !== [] ||
    UX::plugin_links([]) !== []) {
    throw new RuntimeException('Operator permissions or links are wrong');
}
$expires = time() + 1800;
$token = WP_Session_Tokens::get_instance((int)$operator_id)->create($expires);
$cookie_lines = "# Netscape HTTP Cookie File\n";
foreach ([
    AUTH_COOKIE => wp_generate_auth_cookie((int)$operator_id, $expires, 'auth', $token),
    LOGGED_IN_COOKIE => wp_generate_auth_cookie((int)$operator_id, $expires, 'logged_in', $token),
] as $cookie_name => $value) {
    $cookie_lines .= "127.0.0.1\tFALSE\t/\tFALSE\t" . $expires .
        "\t" . $cookie_name . "\t" . $value . "\n";
}
file_put_contents($root . '/operator.cookies', $cookie_lines);
chmod($root . '/operator.cookies', 0600);
echo 'PASS_REAL_WP_GF_ADMIN_OPERATOR_NAV_AND_READ_ONLY_STATES' . PHP_EOL;
