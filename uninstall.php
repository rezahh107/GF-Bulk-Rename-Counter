<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Only the explicit WordPress uninstall removes GFBRC-owned settings.
// Deactivation, ordinary updates and rollback do not execute this file.
delete_option( 'gf_bulk_rename_options' );
delete_option( 'gf_bulk_rename_per_form_rules' );
