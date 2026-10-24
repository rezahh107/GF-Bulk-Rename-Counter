<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Only the explicit WordPress uninstall removes GFBRC-owned settings.
// Deactivation, ordinary updates and rollback do not execute this file.
delete_option( 'gf_bulk_rename_options' );
delete_option( 'gf_bulk_rename_per_form_rules' );
// Exact NEW coverage must survive deactivation/update; explicit uninstall removes it.
global $wpdb;
$keys = $wpdb->get_col( $wpdb->prepare(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like( 'gfbrc_new_coverage_' ) . '%'
) );
foreach ( $keys as $key ) { delete_option( $key ); }
