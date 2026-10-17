<?php
/**
 * Rename handler for bulk downloads - No dependencies on rgar() or any other helper.
 *
 * @package GFBulkRename
 * @since 2.0.6
 */

namespace GFBulkRename;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rename_Handler {

    private int $target_form_id;
    private int $counter_field_id;
    private array $used_filenames = [];

    public function __construct() {
        $options = get_option( Settings_Page::OPTION_NAME, [ 'form_id' => 0, 'counter_field_id' => 0 ] );
        $this->target_form_id   = (int) ( $options['form_id'] ?? 0 );
        $this->counter_field_id = (int) ( $options['counter_field_id'] ?? 0 );
    }

    public function rename_by_counter( string $entry_filename, int $entry_id, string $uploaded_file ): string {
        // No counter field configured
        if ( $this->counter_field_id <= 0 ) {
            return $entry_filename;
        }

        // Fetch entry
        $entry = \GFAPI::get_entry( $entry_id );
        if ( is_wp_error( $entry ) || empty( $entry ) ) {
            $this->log( "Could not fetch entry ID {$entry_id}" );
            return $entry_filename;
        }

        // Check form filter
        if ( $this->target_form_id > 0 && (int) $entry['form_id'] !== $this->target_form_id ) {
            return $entry_filename;
        }

        // Direct array access – no helper functions
        $key = (string) $this->counter_field_id;
        $counter_value = $entry[ $key ] ?? null;

        if ( empty( $counter_value ) || ! is_scalar( $counter_value ) ) {
            $this->log( "Counter field {$this->counter_field_id} empty or invalid for entry {$entry_id}" );
            return $entry_filename;
        }

        $counter_str = trim( (string) $counter_value );
        if ( $counter_str === '' ) {
            return $entry_filename;
        }

        // Build new filename
        $extension = pathinfo( $uploaded_file, PATHINFO_EXTENSION );
        $new_filename = ! empty( $extension )
            ? 'd' . $counter_str . '.' . $extension
            : 'd' . $counter_str;

        $new_filename = \sanitize_file_name( $new_filename );

        // Avoid duplicate names inside the same ZIP
        return $this->make_unique( $new_filename, $entry_id );
    }

    private function make_unique( string $filename, int $entry_id ): string {
        if ( ! in_array( $filename, $this->used_filenames, true ) ) {
            $this->used_filenames[] = $filename;
            return $filename;
        }

        $info = pathinfo( $filename );
        $name = $info['filename'];
        $ext = isset( $info['extension'] ) ? '.' . $info['extension'] : '';
        $unique = $name . '-' . $entry_id . $ext;
        $counter = 1;
        while ( in_array( $unique, $this->used_filenames, true ) ) {
            $unique = $name . '-' . $entry_id . '-' . $counter++ . $ext;
        }
        $this->used_filenames[] = $unique;
        return $unique;
    }

    private function log( string $message ): void {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( '[GF Bulk Rename] ' . $message );
        }
    }
}
