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
$data['nonce'] = wp_create_nonce('gfbrc_independent_flat_zip_' . $data['cases']['one']['form_id']);
$data['endpoint'] = rtrim(getenv('BRC_BASE_URL'), '/') . '/wp-admin/admin-post.php';
file_put_contents($root . '/independent.json', wp_json_encode($data, JSON_PRETTY_PRINT));
echo 'CI admin authenticated nonce bound to existing ephemeral HTTP session.' . PHP_EOL;
