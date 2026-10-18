<?php
/** Read a ZIP saved by real Chromium and compare to selected GF source bytes. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('Real WP/GF unavailable'); }
$root = getenv('BRC_LAB_DIR');
$meta = json_decode(file_get_contents($root . '/browser-selected.json'), true, 512, JSON_THROW_ON_ERROR);
$entry = (int)$meta['selected_id'];
$form_id = (int)$meta['form_id'];
$plan = GFBRC_Independent_Flat_Zip_Experiment::plan($form_id, [$entry]);
$zip = new ZipArchive();
if ($zip->open($root . '/browser-one.zip') !== true) { throw new RuntimeException('Browser ZIP invalid'); }
try {
    if ($zip->numFiles !== count($plan)) { throw new RuntimeException('Browser ZIP lost occurrences'); }
    $observed = [];
    foreach ($plan as $index => $source) {
        $name = $zip->getNameIndex($index);
        if ($name !== $source['name'] || isset($observed[strtolower($name)]) ||
            str_contains($name, '/') || str_contains($name, '\\')) {
            throw new RuntimeException('Browser ZIP member naming mismatch');
        }
        $observed[strtolower($name)] = true;
        $stream = $zip->getStream($name);
        if (!$stream) { throw new RuntimeException('Browser ZIP member not readable'); }
        $hash = hash_init('sha256');
        $size = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) { throw new RuntimeException('Browser ZIP read failure'); }
            $size += strlen($chunk);
            hash_update($hash, $chunk);
        }
        fclose($stream);
        if ($size !== $source['size'] || hash_final($hash) !== $source['sha256']) {
            throw new RuntimeException('Browser ZIP content differed from original GF source');
        }
    }
    echo 'PASS_BROWSER_ZIP_SOURCE_OCCURRENCES entry=' . $entry .
        ' members=' . count($plan) . PHP_EOL;
} finally { $zip->close(); }
