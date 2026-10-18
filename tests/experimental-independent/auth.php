<?php
/** Create exact-user nonces for the existing short-lived CI cookie session. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('WP/GF required'); }
$root = getenv('BRC_LAB_DIR');
$data = json_decode(file_get_contents($root . '/independent.json'), true, 512, JSON_THROW_ON_ERROR);
$user = get_user_by('login', 'bootstrap_admin');
if (!$user) { throw new RuntimeException('Missing CI admin'); }
wp_set_current_user((int)$user->ID);
$lines = file($root . '/auth.cookies', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (str_starts_with($line, '#')) { continue; }
    $parts = preg_split('/\s+/', trim($line));
    if (count($parts) >= 7) { $_COOKIE[$parts[5]] = $parts[6]; }
}
$data['nonces'] = [];
foreach ($data['cases'] as $case) {
    $id = (int)$case['form_id'];
    $data['nonces'][(string)$id] = wp_create_nonce('gfbrc_independent_flat_zip_' . $id);
}
$data['endpoint'] = rtrim(getenv('BRC_BASE_URL'), '/') . '/wp-admin/admin-post.php';
// A real authenticated subscriber with its OWN valid CSRF nonce must still
// fail authorization. Credentials and cookies remain confined to CI /tmp.
$limited_id = wp_create_user('gfbrc_ci_subscriber', wp_generate_password(32), 'gfbrc_ci_subscriber@example.invalid');
if (is_wp_error($limited_id)) { throw new RuntimeException('Could not create CI subscriber'); }
$limited = new WP_User((int)$limited_id);
$limited->set_role('subscriber');
wp_set_current_user((int)$limited_id);
$expires = time() + 1800;
$session_token = WP_Session_Tokens::get_instance((int)$limited_id)->create($expires);
$logged_in = wp_generate_auth_cookie((int)$limited_id, $expires, 'logged_in', $session_token);
$_COOKIE[LOGGED_IN_COOKIE] = $logged_in;
$data['unauthorized_nonce'] = wp_create_nonce('gfbrc_independent_flat_zip_' . $data['cases']['one']['form_id']);
$cookie_lines = "# Netscape HTTP Cookie File\n";
foreach ([
    AUTH_COOKIE => wp_generate_auth_cookie((int)$limited_id, $expires, 'auth', $session_token),
    LOGGED_IN_COOKIE => $logged_in,
] as $cookie_name => $cookie_value) {
    $cookie_lines .= "127.0.0.1\tFALSE\t/\tFALSE\t" . $expires . "\t" . $cookie_name . "\t" . $cookie_value . "\n";
}
file_put_contents($root . '/subscriber.cookies', $cookie_lines);
chmod($root . '/subscriber.cookies', 0600);
if (\GFCommon::current_user_can_any('gravityforms_view_entries')) {
    throw new RuntimeException('Synthetic subscriber unexpectedly has GF Entry access');
}
file_put_contents($root . '/independent.json', wp_json_encode($data, JSON_PRETTY_PRINT));
echo 'CI admin authenticated nonce bound to existing ephemeral HTTP session.' . PHP_EOL;
