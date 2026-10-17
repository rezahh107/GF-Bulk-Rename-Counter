<?php
/** Native GF persistence and source-upload immutability after ZIP extraction. */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'GFAPI' ) ) {
    throw new RuntimeException( 'Authentic WordPress / Gravity Forms missing.' );
}
$manifest = json_decode( (string) file_get_contents( getenv( 'BRC_LAB_DIR' ) . '/manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
foreach ( $manifest['scenarios'] as $scenario => $case ) {
    foreach ( $case['entry_values'] as $entry_id => $values ) {
        $entry = GFAPI::get_entry( (int) $entry_id );
        if ( is_wp_error( $entry ) || (int) $entry['form_id'] !== $case['form_id'] ) {
            throw new RuntimeException( 'Native GF entry missing or mismatched: ' . $entry_id );
        }
        foreach ( $values as $field_id => $expected ) {
            if ( (string) ( $entry[ (string) $field_id ] ?? '' ) !== (string) $expected ) {
                throw new RuntimeException( 'GF entry value mutated for entry ' . $entry_id . ' field ' . $field_id );
            }
        }
    }
    foreach ( $case['uploads'] as $source ) {
        if ( ! is_file( $source['path'] ) || filesize( $source['path'] ) !== $source['size'] ||
            hash_file( 'sha256', $source['path'] ) !== $source['sha256'] ) {
            throw new RuntimeException( 'Original upload modified or missing: ' . $source['path'] );
        }
    }
}
echo 'Native GF entry values and original upload bytes unchanged: PASS' . PHP_EOL;
