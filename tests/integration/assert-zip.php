<?php
/**
 * Fail-closed native ZIP integrity checks, plus exact host-collision qualification.
 * Usage: php assert-zip.php MANIFEST SCENARIO ZIP
 */
declare(strict_types=1);

if ( $argc !== 4 ) {
    fwrite( STDERR, "Usage: assert-zip.php manifest.json scenario download.zip\n" );
    exit( 2 );
}
$manifest = json_decode( (string) file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
$case = $manifest['scenarios'][ $argv[2] ] ?? null;
if ( ! is_array( $case ) || ! is_array( $case['expected'] ?? null ) ||
    ! is_array( $case['uploads'] ?? null ) || ! $case['uploads'] ) {
    throw new RuntimeException( 'Missing case/independent source expectations' );
}

// Every genuine uploaded source must have an independently recorded digest.
// Do not silently drop malformed upload rows (array_column would do so).
$source_hashes = [];
foreach ( $case['uploads'] as $upload ) {
    if ( ! is_array( $upload ) || ! is_string( $upload['sha256'] ?? null ) ||
        ! preg_match( '/^[a-f0-9]{64}$/D', $upload['sha256'] ) ) {
        throw new RuntimeException( 'Missing or invalid source upload digest' );
    }
    $source_hashes[] = $upload['sha256'];
}

$diagnostic = ( $case['diagnostic_collision'] ?? false ) === true;
if ( isset( $case['diagnostic_collision'] ) && ! $diagnostic ) {
    throw new RuntimeException( 'Invalid diagnostic flag' );
}
if ( $diagnostic ) {
    // Exact, independently named host defects. A diagnostic must never silently
    // admit arbitrary archive loss or become an ordinary integrity PASS.
    $ids = $case['entry_ids'] ?? [];
    $first_id = $ids[0] ?? null;
    $diagnostic_specs = [
        'host_folder_collision' => [ 'path' => 'shared/same.jpg', 'basename' => 'same.jpg', 'entry_count' => 2 ],
        'default_equal_within_entry' => [ 'path' => $first_id . '/same.jpg', 'basename' => 'same.jpg', 'entry_count' => 1 ],
        'entry_folder_equal_within_entry' => [ 'path' => 'shared-' . $first_id . '/same.jpg', 'basename' => 'same.jpg', 'entry_count' => 1 ],
        'shared_sanitized_upload_collision' => [ 'path' => 'shared/ab.jpg', 'basename' => 'ab.jpg', 'entry_count' => 2 ],
    ];
    $diagnostic_spec = $diagnostic_specs[ $argv[2] ] ?? null;
    $paths = array_column( $case['uploads'], 'path' );
    if ( ! $diagnostic_spec ||
        $case['expected'] !== [] ||
        ! is_int( $first_id ) || $first_id <= 0 ||
        count( $ids ) !== $diagnostic_spec['entry_count'] ||
        count( array_unique( $ids ) ) !== $diagnostic_spec['entry_count'] ||
        ( $case['expected_path_prefix'] ?? null ) !== dirname( $diagnostic_spec['path'] ) . '/' ||
        count( $source_hashes ) !== 2 || count( array_unique( $source_hashes ) ) !== 2 ||
        count( $paths ) !== 2 || count( array_unique( $paths ) ) !== 2 ||
        ! is_string( $paths[0] ) || ! is_string( $paths[1] ) ||
        basename( $paths[0] ) !== $diagnostic_spec['basename'] ||
        basename( $paths[1] ) !== $diagnostic_spec['basename'] ||
        dirname( $paths[0] ) === dirname( $paths[1] ) ) {
        throw new RuntimeException( 'Diagnostic source identity/conformance not proven' );
    }
    $submitted_names = array_column( $case['uploads'], 'submitted_name' );
    if ( $argv[2] === 'shared_sanitized_upload_collision' ) {
        if ( $submitted_names !== [ 'a+b.jpg', 'ab.jpg' ] ) {
            throw new RuntimeException( 'Distinct pre-sanitization source names not proven' );
        }
    } elseif ( $submitted_names !== [ 'same.jpg', 'same.jpg' ] &&
        // Old PR #4 source manifest did not require submitted_name;
        // the old exact host diagnostic already proves stored basenames.
        !( $argv[2] === 'host_folder_collision' && $submitted_names === [] ) ) {
        throw new RuntimeException( 'Diagnostic supplied-basename provenance not proven' );
    }
} elseif ( ! $case['expected'] ) {
    throw new RuntimeException( 'Ordinary ZIP has no exact path expectations' );
}

$zip = new ZipArchive();
if ( $zip->open( $argv[3] ) !== true ) {
    throw new RuntimeException( 'Invalid ZIP: ' . $argv[3] );
}
$exit_code = 0;
try {
    $actual = [];
    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
        $name = $zip->getNameIndex( $i );
        $bytes = $zip->getFromIndex( $i );
        if ( ! is_string( $name ) || ! is_string( $bytes ) ) {
            throw new RuntimeException( 'Unreadable archive member' );
        }
        // Always reject duplicates before any diagnostic classification.
        if ( array_key_exists( $name, $actual ) ) {
            throw new RuntimeException( 'Duplicate ZIP internal path: ' . $name );
        }
        $actual[ $name ] = hash( 'sha256', $bytes );
    }

    $check = static function ( array $paths, array $required_hashes ) use ( $zip, $actual, $case ): void {
        if ( $zip->numFiles !== count( $required_hashes ) ) {
            throw new RuntimeException( 'Missing / overwritten source uploads: expected ' . count( $required_hashes ) . ', observed ' . $zip->numFiles );
        }
        $observed_hashes = array_values( $actual );
        sort( $observed_hashes );
        sort( $required_hashes );
        if ( $observed_hashes !== $required_hashes ) {
            throw new RuntimeException( 'Source upload contents missing, duplicated or changed in ZIP' );
        }
        if ( $paths ) {
            ksort( $paths );
            $sorted = $actual;
            ksort( $sorted );
            if ( $sorted !== $paths ) {
                throw new RuntimeException( 'ZIP paths or file checksums differ: expected ' . json_encode( $paths ) . ', observed ' . json_encode( $sorted ) );
            }
        }
        if ( isset( $case['expected_path_prefix'] ) ) {
            foreach ( array_keys( $actual ) as $path ) {
                if ( ! str_starts_with( $path, $case['expected_path_prefix'] ) ) {
                    throw new RuntimeException( 'Member outside native custom folder: ' . $path );
                }
            }
        }
    };

    $state = 'PASS_NATIVE_ZIP';
    $violation = null;
    $negative_detected = false;
    if ( $diagnostic ) {
        // Both actual bytes and genuine source hashes control this decision.
        // A clean archive means the expected collision was NOT reproduced.
        foreach ( array_keys( $actual ) as $name ) {
            if ( ! str_starts_with( $name, 'shared/' ) ) {
                throw new RuntimeException( 'Unexpected diagnostic ZIP member path: ' . $name );
            }
        }
        $observed_hashes = array_values( $actual );
        $ordered_actual = $observed_hashes;
        $ordered_sources = $source_hashes;
        sort( $ordered_actual );
        sort( $ordered_sources );
        if ( $zip->numFiles === 2 && $ordered_actual === $ordered_sources ) {
            $state = 'NOT_REPRODUCED';
            $violation = 'Both distinct original uploads survived; requalify the expected host collision';
            $exit_code = 1;
        } elseif ( $zip->numFiles === 1 && array_keys( $actual ) === [ $diagnostic_spec['path'] ] &&
            in_array( $actual[ $diagnostic_spec['path'] ], $source_hashes, true ) ) {
            $state = 'NATIVE_ZIP_INTEGRITY_VIOLATION_OBSERVED';
            $violation = 'Missing / overwritten source uploads: expected 2, observed 1';
        } else {
            throw new RuntimeException( 'Observed ZIP does not conform to the exact two-source / one-member host collision' );
        }
    } else {
        $check( $case['expected'], $source_hashes );
        // The same ordinary integrity checker must detect wrong source bytes.
        $mutated_hashes = $source_hashes;
        $mutated_hashes[0] = str_repeat( '0', 64 );
        if ( $mutated_hashes[0] === $source_hashes[0] ) {
            $mutated_hashes[0] = str_repeat( '1', 64 );
        }
        try {
            $check( $case['expected'], $mutated_hashes );
        } catch ( RuntimeException $e ) {
            $negative_detected = true;
        }
        if ( ! $negative_detected ) {
            throw new RuntimeException( 'Negative control failed: wrong source digest was accepted' );
        }
    }

    echo json_encode( [
        'scenario' => $argv[2],
        'state' => $state,
        'violation' => $violation,
        'members' => $actual,
        'count' => $zip->numFiles,
        'required_source_count' => count( $source_hashes ),
        'archive_sha256' => hash_file( 'sha256', $argv[3] ),
        'negative_wrong_checksum_rejected' => $negative_detected,
        'diagnostic_only' => $diagnostic,
        'expected_collision_member' => $diagnostic ? $diagnostic_spec['path'] : null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
} finally {
    $zip->close();
}
exit( $exit_code );
