<?php
/** Execute the actual registered host bulk action, streaming its ZIP to stdout. */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'GFAPI' ) ) {
    throw new RuntimeException( 'WP / GF unavailable.' );
}
$scenario = getenv( 'BRC_SCENARIO' );
$data = json_decode( file_get_contents( getenv( 'BRC_LAB_DIR' ) . '/manifest.json' ), true );
if ( ! isset( $data['scenarios'][ $scenario ] ) ) {
    throw new RuntimeException( 'Unknown scenario.' );
}
$s = $data['scenarios'][ $scenario ];
$host_only = getenv( 'BRC_HOST_ONLY' ) === '1';
if ( ! has_action( 'gform_entry_list_action' ) ) {
    throw new RuntimeException( 'Native bulk action missing.' );
}
if ( $host_only && has_filter( 'bdfgf_entry_filename' ) ) {
    throw new RuntimeException( 'Host-only control still has a filename filter.' );
}
if ( ! $host_only && ! has_filter( 'bdfgf_entry_filename' ) ) {
    throw new RuntimeException( 'Target filename filter missing.' );
}
do_action( 'gform_entry_list_action', 'gf_bulk_download', $s['entry_ids'], $s['form_id'] );
throw new RuntimeException( 'Native BulkDownload unexpectedly returned without streaming a ZIP.' );
