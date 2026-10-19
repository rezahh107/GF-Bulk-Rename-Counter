<?php
/** Verify production writer's fail-closed error path and closed-ZIP verifier. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('WordPress/GF required'); }
$root = getenv('BRC_LAB_DIR');
$data = json_decode(file_get_contents($root . '/independent.json'), true, 512, JSON_THROW_ON_ERROR);
$case = $data['cases']['one'];
$writer = \GFBulkRename\Flat_Zip_Download::class;
$plan = $writer::plan($case['form_id'], $case['entry_ids']);
$prefix = rtrim(get_temp_dir(), '/\\') . '/gfbrc-independent-flat*';
$before = glob($prefix) ?: [];
if ($before) { throw new RuntimeException('Previous download left a temporary ZIP'); }
$broken = $plan;
$broken[0]['source'] = '/definitely/missing/file.jpg';
try {
    $written = $writer::write_verified_zip($broken);
    @unlink($written);
    throw new RuntimeException('Missing writer source was accepted');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'ZIP addFile failed')) { throw $e; }
}
if ((glob($prefix) ?: []) !== $before) {
    throw new RuntimeException('ZIP temporary archive leaked after addFile error');
}
echo 'PASS_PRODUCTION_ADD_FAILURE_CLEANUP' . PHP_EOL;
foreach (['missing','extra','overwrite','corrupt'] as $mode) {
    $temp = $writer::write_verified_zip($plan);
    try {
        if ($mode === 'corrupt') {
            file_put_contents($temp, 'corrupt');
        } else {
            $z = new ZipArchive();
            if ($z->open($temp) !== true) { throw new RuntimeException('Cannot mutate ZIP'); }
            if ($mode === 'missing') { $z->deleteName($plan[0]['name']); }
            if ($mode === 'extra') { $z->addFromString('undeclared.txt', 'extra'); }
            if ($mode === 'overwrite') { $z->addFromString($plan[0]['name'], 'wrong bytes'); }
            if (!$z->close()) { throw new RuntimeException('Mutation setup close failed'); }
        }
        try {
            $writer::verify_closed_zip($temp, $plan);
            throw new RuntimeException('Corrupted ZIP unexpectedly verified: ' . $mode);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'unexpectedly verified')) { throw $e; }
            echo 'PASS_PRODUCTION_CLOSED_ZIP_REJECTED ' . $mode . PHP_EOL;
        }
    } finally { @unlink($temp); }
}
if ((glob($prefix) ?: []) !== $before) { throw new RuntimeException('ZIP temporary archive leak'); }
