<?php
/**
 * Fail-closed native ZIP integrity checks, plus deliberate checksum negative control.
 * Usage: php assert-zip.php MANIFEST SCENARIO ZIP
 */
declare(strict_types=1);

if ( $argc !== 4 ) {
    fwrite( STDERR, "Usage: assert-zip.php manifest.json scenario download.zip\n" );
    exit( 2 );
}
$manifest = json_decode( (string) file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
$case = $manifest['scenarios'][ $argv[2] ] ?? null;
if ( ! is_array( $case ) || ! is_array( $case['expected'] ?? null ) ) {
    throw new RuntimeException( 'Missing case/independent expectations' );
}
$zip = new ZipArchive();
if ( $zip->open( $argv[3] ) !== true ) {
    throw new RuntimeException( 'Invalid ZIP: ' . $argv[3] );
}
try {
    $actual = [];
    $duplicates = [];
    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
        $name = $zip->getNameIndex( $i );
        $bytes = $zip->getFromIndex( $i );
        if ( ! is_string( $name ) || ! is_string( $bytes ) ) {
            throw new RuntimeException( 'Unreadable archive member' );
        }
        if ( array_key_exists( $name, $actual ) ) {
            $duplicates[] = $name;
        }
        $actual[ $name ] = hash( 'sha256', $bytes );
    }

    // Compare independently recorded source payloads as well as ZIP paths.
    // A map of expected ZIP paths alone cannot represent two legitimate
    // uploads whose host-configured member paths collide.
    $source_hashes = array_column( $case['uploads'], 'sha256' );
    if ( ! $source_hashes || count( $source_hashes ) !== count( $case['uploads'] ) ) {
        throw new RuntimeException( 'Missing independently recorded upload hashes' );
    }
    $expected_paths = $case['expected'];
    $check = static function ( array $paths, array $required_hashes ) use ( $zip, $actual, $duplicates, $case ): void {
        if ( $duplicates ) {
            throw new RuntimeException( 'Duplicate ZIP internal paths: ' . implode( ', ', $duplicates ) );
        }
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

    $diagnostic = ! empty( $case['diagnostic_collision'] );
    $integrity_error = null;
    try {
        $check( $expected_paths, $source_hashes );
    } catch ( RuntimeException $e ) {
        $integrity_error = $e;
    }
    if ( $integrity_error !== null && ! $diagnostic ) {
        throw $integrity_error;
    }

    $negative_detected = false;
    if ( $integrity_error === null ) {
        // Negative control exercises the SAME checker with a deliberately
        // incorrect source digest; no production archive is altered.
        $mutated_hashes = $source_hashes;
        $mutated_hashes[0] = str_repeat( '0', 64 );
        if ( $mutated_hashes[0] === $source_hashes[0] ) {
            $mutated_hashes[0] = str_repeat( '1', 64 );
        }
        try {
            $check( $expected_paths, $mutated_hashes );
        } catch ( RuntimeException $e ) {
            $negative_detected = true;
        }
        if ( ! $negative_detected ) {
            throw new RuntimeException( 'Negative control failed: wrong source digest was accepted' );
        }
    }
    echo json_encode( [
        'scenario' => $argv[2],
        'state' => $integrity_error === null ? 'PASS_NATIVE_ZIP' : 'NATIVE_ZIP_INTEGRITY_VIOLATION_OBSERVED',
        'violation' => $integrity_error === null ? null : $integrity_error->getMessage(),
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
