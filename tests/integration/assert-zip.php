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
    // Scope guard: the expected native host collision, never arbitrary ZIP loss.
    $paths = array_column( $case['uploads'], 'path' );
    if ( $argv[2] !== 'host_folder_collision' ||
        $case['expected'] !== [] ||
        ( $case['expected_path_prefix'] ?? null ) !== 'shared/' ||
        count( $source_hashes ) !== 2 || count( array_unique( $source_hashes ) ) !== 2 ||
        count( $case['entry_ids'] ?? [] ) !== 2 ||
        count( array_unique( $case['entry_ids'] ) ) !== 2 ||
        count( $paths ) !== 2 || count( array_unique( $paths ) ) !== 2 ||
        ! is_string( $paths[0] ) || ! is_string( $paths[1] ) ||
        basename( $paths[0] ) !== 'same.jpg' || basename( $paths[1] ) !== 'same.jpg' ||
        dirname( $paths[0] ) === dirname( $paths[1] ) ) {
        throw new RuntimeException( 'Diagnostic source identity/conformance not proven' );
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
        } elseif ( $zip->numFiles === 1 && array_keys( $actual ) === [ 'shared/same.jpg' ] &&
            in_array( $actual['shared/same.jpg'], $source_hashes, true ) ) {
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
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
} finally {
    $zip->close();
}
exit( $exit_code );
