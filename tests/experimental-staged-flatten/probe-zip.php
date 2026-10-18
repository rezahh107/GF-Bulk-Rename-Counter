<?php
/**
 * Non-production native ZipArchive behavior probe. Run under PHP 8.2.34 + ext-zip.
 * Tests the open archive, not a rewritten/extracted copy.
 */
if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('ZIP_EXTENSION_UNAVAILABLE');
}
$root = sys_get_temp_dir() . '/gfb-zip-rename-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) {
    throw new RuntimeException('PROBE_TEMP_FAILED');
}
$source = $root . '/physical.bin';
file_put_contents($source, "same physical source\0payload");
$report = ['php' => PHP_VERSION, 'libzip' => ZipArchive::LIBZIP_VERSION];
$started = microtime(true);
try {
    $path = $root . '/multiple.zip';
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('PROBE_OPEN_FAILED');
    }
    foreach (['entry1/a.txt' => 'FIRST', 'entry2/b.txt' => 'SECOND', 'entry2/c.txt' => 'THIRD'] as $name => $value) {
        if (!$zip->addFromString($name, $value)) {
            throw new RuntimeException('PROBE_ADD_FAILED');
        }
    }
    $report['before'] = array_map(fn($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    $report['rename0'] = $zip->renameIndex(0, 'a.txt');
    $report['rename2'] = $zip->renameIndex(2, 'c.txt');
    $report['rename1'] = $zip->renameIndex(1, 'b.txt');
    $report['open_names'] = array_map(fn($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    $report['locate'] = ['old' => $zip->locateName('entry1/a.txt'), 'new' => $zip->locateName('a.txt')];
    if (in_array(false, [$report['rename0'], $report['rename2'], $report['rename1']], true) || !$zip->close()) {
        throw new RuntimeException('PROBE_SAFE_RENAME_FAILED');
    }
    $reader = new ZipArchive();
    if ($reader->open($path) !== true) {
        throw new RuntimeException('PROBE_REOPEN_FAILED');
    }
    $seen = [];
    for ($i = 0; $i < $reader->numFiles; $i++) {
        $seen[$reader->getNameIndex($i)] = $reader->getFromIndex($i);
    }
    $reader->close();
    $report['closed_names'] = array_keys($seen);
    if (count($seen) !== 3 || $seen !== ['a.txt' => 'FIRST', 'b.txt' => 'SECOND', 'c.txt' => 'THIRD'] &&
        $seen !== ['a.txt' => 'FIRST', 'c.txt' => 'THIRD', 'b.txt' => 'SECOND']) {
        throw new RuntimeException('PROBE_POST_CLOSE_CONTENT_MISMATCH');
    }

    $collision = $root . '/collision.zip';
    $zip = new ZipArchive();
    if ($zip->open($collision, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true ||
        !$zip->addFromString('a/original.txt', 'A') || !$zip->addFromString('b/occupied.txt', 'B')) {
        throw new RuntimeException('PROBE_COLLISION_SETUP_FAILED');
    }
    $report['occupied_rename_result'] = @$zip->renameIndex(0, 'b/occupied.txt');
    $report['occupied_open_count'] = $zip->numFiles;
    $report['occupied_open_names'] = [$zip->getNameIndex(0), $zip->getNameIndex(1)];
    $report['occupied_close'] = @$zip->close();
    $reader = new ZipArchive();
    $reopened = $reader->open($collision);
    $report['occupied_reopen_status'] = $reopened;
    if ($reopened === true) {
        $report['occupied_closed_count'] = $reader->numFiles;
        $report['occupied_closed_names'] = [];
        $report['occupied_closed_hashes'] = [];
        for ($i = 0; $i < $reader->numFiles; $i++) {
            $name = $reader->getNameIndex($i);
            $report['occupied_closed_names'][] = $name;
            $report['occupied_closed_hashes'][] = hash('sha256', (string)$reader->getFromIndex($i));
        }
        $reader->close();
    }

    $physical = $root . '/physical.zip';
    $zip = new ZipArchive();
    if ($zip->open($physical, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true ||
        !$zip->addFile($source, 'stage/1/p.bin') ||
        !$zip->addFile($source, 'stage/2/p.bin') ||
        !$zip->renameIndex(0, 'p.bin') ||
        !$zip->renameIndex(1, 'p-2.bin') ||
        !$zip->close()) {
        throw new RuntimeException('PROBE_REPEATED_SOURCE_FAILED');
    }
    $reader = new ZipArchive();
    if ($reader->open($physical) !== true || $reader->numFiles !== 2 ||
        $reader->getFromName('p.bin') !== file_get_contents($source) ||
        $reader->getFromName('p-2.bin') !== file_get_contents($source)) {
        throw new RuntimeException('PROBE_REPEATED_SOURCE_INTEGRITY_FAILED');
    }
    $reader->close();
    $report['duplicate_physical_paths_preserved'] = true;
    $report['peak_memory_bytes'] = memory_get_peak_usage(true);
    $report['elapsed_seconds'] = round(microtime(true) - $started, 6);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    foreach (glob($root . '/*') ?: [] as $item) {
        @unlink($item);
    }
    @rmdir($root);
}
