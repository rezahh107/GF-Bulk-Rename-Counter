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

    $check = static function ( array $expected ) use ( $zip, $actual, $duplicates ): void {
        if ( $duplicates ) {
            throw new RuntimeException( 'Duplicate ZIP internal paths: ' . implode( ', ', $duplicates ) );
        }
        if ( $zip->numFiles !== count( $expected ) ) {
            throw new RuntimeException( 'Missing / overwritten ZIP entries: expected ' . count( $expected ) . ', observed ' . $zip->numFiles );
        }
        ksort( $expected );
        $sorted = $actual;
        ksort( $sorted );
        if ( $sorted !== $expected ) {
            throw new RuntimeException( 'ZIP paths or file checksums differ: expected ' . json_encode( $expected ) . ', observed ' . json_encode( $sorted ) );
        }
    };

    // The negative control must demonstrate detection of wrong bytes after a real ZIP passed.
    $check( $case['expected'] );
    $mutated = $case['expected'];
    $first_key = array_key_first( $mutated );
    if ( $first_key === null ) {
        throw new RuntimeException( 'Empty expected archive is forbidden' );
    }
    $mutated[ $first_key ] = str_repeat( '0', 64 );
    if ( $mutated[ $first_key ] === $case['expected'][ $first_key ] ) {
        $mutated[ $first_key ] = str_repeat( '1', 64 );
    }
    $negative_detected = false;
    try {
        $check( $mutated );
    } catch ( RuntimeException $e ) {
        $negative_detected = true;
    }
    if ( ! $negative_detected ) {
        throw new RuntimeException( 'Negative control failed: wrong member digest was accepted' );
    }
    echo json_encode( [
        'scenario' => $argv[2],
        'state' => 'PASS_NATIVE_ZIP',
        'members' => $actual,
        'count' => $zip->numFiles,
        'archive_sha256' => hash_file( 'sha256', $argv[3] ),
        'negative_wrong_checksum_rejected' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
} finally {
    $zip->close();
}
