<?php
/**
 * Synthetic WordPress / Gravity Forms fixture. Never loads stub APIs.
 * Run with WP-CLI eval-file after all three real plugins are active.
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'GFAPI' ) ) {
    throw new RuntimeException( 'Real WordPress and Gravity Forms are required.' );
}

$root = getenv( 'BRC_LAB_DIR' );
if ( ! $root || ! is_dir( $root ) ) {
    throw new RuntimeException( 'BRC_LAB_DIR must exist.' );
}
if ( ! defined( 'BDFGF_VERSION' ) || BDFGF_VERSION !== '4.2.0' ) {
    throw new RuntimeException( 'Bulk Download 4.2.0 is not active.' );
}
$expected_target_version = getenv( 'BRC_TARGET_VERSION' ) ?: '2.0.6';
if ( ! defined( 'GFBULKRENAME_VERSION' ) || GFBULKRENAME_VERSION !== $expected_target_version ) {
    throw new RuntimeException( 'Target package/version is not active.' );
}
if ( ! class_exists( 'BDFGF\\Actions\\BulkDownload' ) ) {
    throw new RuntimeException( 'Native BulkDownload action class unavailable.' );
}
$has_native = false;
foreach ( $GLOBALS['wp_filter']['gform_entry_list_action']->callbacks ?? [] as $bucket ) {
    foreach ( $bucket as $callback ) {
        if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof \BDFGF\Actions\BulkDownload ) {
            $has_native = true;
        }
    }
}
$has_target = false;
foreach ( $GLOBALS['wp_filter']['bdfgf_entry_filename']->callbacks ?? [] as $bucket ) {
    foreach ( $bucket as $callback ) {
        if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof \GFBulkRename\Rename_Handler ) {
            $has_target = true;
        }
    }
}
if ( ! $has_native || ! $has_target ) {
    throw new RuntimeException( 'Native action / target filename filter are not both registered.' );
}

update_option( 'gf_bulk_rename_options', [ 'form_id' => 0, 'counter_field_id' => 5 ] );
$cases = [
    'single' => [ [ 'counter' => '125', 'single' => [ 'single-a.jpg' ] ] ],
    'shared' => [
        [ 'counter' => '125', 'single' => [ 'shared-a.jpg' ] ],
        [ 'counter' => '125', 'single' => [ 'shared-b.jpg' ] ],
    ],
    'multiple' => [
        [ 'counter' => '125', 'single' => [ 'multiple-a.jpg' ], 'multi' => [ 'multiple-b.jpg', 'multiple-c.jpg' ] ],
    ],
    'collision_attempt' => [
        [ 'counter' => '125', 'single' => [ 'collision-a.jpg' ] ],
        [ 'counter' => '0', 'single' => [ 'd125.jpg' ] ],
    ],
    'sanitized_equal' => [
        [ 'counter' => 'A?B', 'single' => [ 'sanitized-a.jpg' ] ],
        [ 'counter' => 'AB', 'single' => [ 'sanitized-b.jpg' ] ],
    ],
    'multi_entry_files' => [
        [ 'counter' => '125', 'single' => [ 'many-a.jpg' ], 'multi' => [ 'many-b.jpg', 'many-c.jpg' ] ],
        [ 'counter' => '125', 'single' => [ 'many-d.jpg' ], 'multi' => [ 'many-e.jpg' ] ],
    ],
    'custom_folder_mixed' => [
        [ 'counter' => '125', 'single' => [ 'folder-renamed.jpg' ] ],
        [ 'counter' => '0', 'single' => [ 'folder-original.jpg' ] ],
    ],
    // Same basename in two *real* WP upload month directories; no test-only filter.
    // Both entries are unrenamed and use one host-supported custom static folder.
    'host_folder_collision' => [
        [ 'counter' => '0', 'single' => [ [ 'name' => 'same.jpg', 'time' => '2025/01' ] ] ],
        [ 'counter' => '0', 'single' => [ [ 'name' => 'same.jpg', 'time' => '2025/02' ] ] ],
    ],
    'independent_form_a' => [ [ 'counter' => '125', 'single' => [ 'form-a.jpg' ] ] ],
    'independent_form_b' => [ [ 'counter' => '125', 'single' => [ 'form-b.jpg' ] ] ],
];
$manifest = [ 'schema' => '1', 'scenarios' => [] ];
foreach ( $cases as $name => $definitions ) {
    $form = [
        'title' => 'BRC synthetic ' . $name,
        'fields' => [
            [ 'id' => 1, 'type' => 'fileupload', 'label' => 'Single file' ],
            [ 'id' => 2, 'type' => 'fileupload', 'label' => 'Multiple files', 'multipleFiles' => true ],
            [ 'id' => 5, 'type' => 'text', 'label' => 'Counter' ],
        ],
        'button' => [ 'type' => 'text', 'text' => 'Submit' ],
    ];
    if ( in_array( $name, [ 'custom_folder_mixed', 'host_folder_collision' ], true ) ) {
        $form['bulkDownloadSettings'] = [
            'customFoldername' => true,
            'downloadFoldername' => 'shared',
        ];
    }
    $form_id = GFAPI::add_form( $form );
    if ( is_wp_error( $form_id ) ) {
        throw new RuntimeException( $form_id->get_error_message() );
    }
    $persisted_form = GFAPI::get_form( $form_id );
    if ( is_wp_error( $persisted_form ) || ! is_array( $persisted_form ) ) {
        throw new RuntimeException( 'Created native GF form unavailable.' );
    }
    if ( isset( $form['bulkDownloadSettings'] ) &&
        ( $persisted_form['bulkDownloadSettings'] ?? null ) !== $form['bulkDownloadSettings'] ) {
        throw new RuntimeException( 'Native GF form did not preserve host folder configuration.' );
    }
    $data = [ 'form_id' => (int) $form_id, 'entry_ids' => [], 'entry_values' => [], 'uploads' => [], 'expected' => [] ];
    $ordered = [];
    foreach ( $definitions as $definition ) {
        $values = [ '5' => $definition['counter'] ];
        $sources = [];
        foreach ( [ 'single' => '1', 'multi' => '2' ] as $kind => $field_id ) {
            $urls = [];
            foreach ( $definition[ $kind ] ?? [] as $file_spec ) {
                $filename = is_array( $file_spec ) ? $file_spec['name'] : $file_spec;
                $upload_time = is_array( $file_spec ) ? $file_spec['time'] : null;
                $bytes = 'brc-fixture|' . $name . '|' . $kind . '|' . $filename . '|' . ( $upload_time ?? 'default' ) . '|END';
                $uploaded = wp_upload_bits( $filename, null, $bytes, $upload_time );
                if ( ! empty( $uploaded['error'] ) || ! is_file( $uploaded['file'] ) ) {
                    throw new RuntimeException( 'wp_upload_bits failed: ' . ( $uploaded['error'] ?? 'missing file' ) );
                }
                if ( $upload_time !== null && wp_basename( $uploaded['file'] ) !== $filename ) {
                    throw new RuntimeException( 'WordPress changed collision fixture basename; cannot qualify native folder collision.' );
                }
                $urls[] = $uploaded['url'];
                $sources[] = [
                    'path' => $uploaded['file'],
                    'url' => $uploaded['url'],
                    'sha256' => hash( 'sha256', $bytes ),
                    'size' => strlen( $bytes ),
                ];
            }
            if ( $urls ) {
                $values[ $field_id ] = $kind === 'single' ? $urls[0] : wp_json_encode( $urls );
            }
        }
        // Preserve numeric GF field IDs: array_merge() would reindex field '5'.
        $entry = [ 'form_id' => (int) $form_id ] + $values;
        $entry_id = GFAPI::add_entry( $entry );
        if ( is_wp_error( $entry_id ) ) {
            throw new RuntimeException( $entry_id->get_error_message() );
        }
        $id = (int) $entry_id;
        $stored = GFAPI::get_entry( $id );
        if ( is_wp_error( $stored ) || ! is_array( $stored ) ) {
            throw new RuntimeException( 'Native GF entry did not persist: ' . $id );
        }
        foreach ( $values as $field_id => $expected_value ) {
            if ( (string) ( $stored[ (string) $field_id ] ?? '' ) !== (string) $expected_value ) {
                throw new RuntimeException( 'GF fixture field ID ' . $field_id . ' did not persist intact for entry ' . $id );
            }
        }
        $data['entry_ids'][] = $id;
        $data['entry_values'][ (string) $id ] = $values;
        $data['uploads'] = array_merge( $data['uploads'], $sources );
        $ordered[] = [ 'entry_id' => $id, 'sources' => $sources ];
    }
    // Expectations are independently specified for each bounded scenario.
    $first = $ordered[0];
    if ( $name === 'single' ) {
        $data['expected']['d125.jpg'] = $first['sources'][0]['sha256'];
    } elseif ( $name === 'shared' ) {
        $second = $ordered[1];
        $data['expected']['d125.jpg'] = $first['sources'][0]['sha256'];
        $data['expected']['d125-' . $second['entry_id'] . '.jpg'] = $second['sources'][0]['sha256'];
    } elseif ( $name === 'multiple' ) {
        $id = $first['entry_id'];
        $data['expected']['d125.jpg'] = $first['sources'][0]['sha256'];
        $data['expected']['d125-' . $id . '.jpg'] = $first['sources'][1]['sha256'];
        $data['expected']['d125-' . $id . '-1.jpg'] = $first['sources'][2]['sha256'];
    } elseif ( $name === 'collision_attempt' ) {
        $second = $ordered[1];
        $data['expected']['d125.jpg'] = $first['sources'][0]['sha256'];
        $data['expected'][ $second['entry_id'] . '/d125.jpg' ] = $second['sources'][0]['sha256'];
    } elseif ( $name === 'sanitized_equal' ) {
        $second = $ordered[1];
        $data['expected']['dAB.jpg'] = $first['sources'][0]['sha256'];
        $data['expected']['dAB-' . $second['entry_id'] . '.jpg'] = $second['sources'][0]['sha256'];
    } elseif ( $name === 'multi_entry_files' ) {
        $second = $ordered[1];
        $data['expected']['d125.jpg'] = $first['sources'][0]['sha256'];
        $data['expected']['d125-' . $first['entry_id'] . '.jpg'] = $first['sources'][1]['sha256'];
        $data['expected']['d125-' . $first['entry_id'] . '-1.jpg'] = $first['sources'][2]['sha256'];
        $data['expected']['d125-' . $second['entry_id'] . '.jpg'] = $second['sources'][0]['sha256'];
        $data['expected']['d125-' . $second['entry_id'] . '-1.jpg'] = $second['sources'][1]['sha256'];
    } elseif ( $name === 'custom_folder_mixed' ) {
        $second = $ordered[1];
        $data['expected']['d125.jpg'] = $first['sources'][0]['sha256'];
        $data['expected']['shared/folder-original.jpg'] = $second['sources'][0]['sha256'];
    } elseif ( $name === 'host_folder_collision' ) {
        // No approved collision naming policy. Require conservation of BOTH source
        // payloads; never bless a one-member overwritten ZIP as an expected output.
        $data['diagnostic_collision'] = true;
        $data['expected_path_prefix'] = 'shared/';
    } else {
        $data['expected']['d125.jpg'] = $first['sources'][0]['sha256'];
    }
    $manifest['scenarios'][ $name ] = $data;
}
$path = $root . '/manifest.json';
if ( false === file_put_contents( $path, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) {
    throw new RuntimeException( 'Cannot write fixture manifest.' );
}
echo 'Native hook and filename filter reachability: PASS; synthetic forms and entries: PASS' . PHP_EOL;
