<?php
/**
 * Mint an ephemeral real WP authenticated session, nonce and Netscape cookie jar.
 * Never upload auth.json or auth.cookies as CI artifacts.
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'GFAPI' ) ) {
    throw new RuntimeException( 'Native WP / GF required' );
}
$root = getenv( 'BRC_LAB_DIR' );
$manifest = json_decode( file_get_contents( $root . '/manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
$case = $manifest['scenarios']['single'];
$admin = get_user_by( 'login', 'bootstrap_admin' );
if ( ! $admin ) {
    throw new RuntimeException( 'Synthetic admin not found.' );
}
wp_set_current_user( (int) $admin->ID );
$expires = time() + 1800;
$token = WP_Session_Tokens::get_instance( (int) $admin->ID )->create( $expires );
$auth = wp_generate_auth_cookie( (int) $admin->ID, $expires, 'auth', $token );
$logged_in = wp_generate_auth_cookie( (int) $admin->ID, $expires, 'logged_in', $token );
$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in;
$entry_id = $case['entry_ids'][0];
$nonce = wp_create_nonce( 'bdfgf_bulk_download_entry_' . $entry_id );
$url = admin_url( 'admin.php?action=gf_bulk_download&gf_form_id=' . $case['form_id'] . '&gf_entry_id=' . $entry_id . '&_wpnonce=' . rawurlencode( $nonce ) );
$cookie_jar = "# Netscape HTTP Cookie File\n";
foreach ( [ AUTH_COOKIE => $auth, LOGGED_IN_COOKIE => $logged_in ] as $name => $value ) {
    $cookie_jar .= "127.0.0.1\tFALSE\t/\tFALSE\t" . $expires . "\t" . $name . "\t" . $value . "\n";
}
file_put_contents( $root . '/auth.cookies', $cookie_jar );
chmod( $root . '/auth.cookies', 0600 );
file_put_contents( $root . '/auth.json', wp_json_encode( [ 'url' => $url ] ) );
chmod( $root . '/auth.json', 0600 );
echo 'Ephemeral authentication and native download nonce prepared.' . PHP_EOL;
