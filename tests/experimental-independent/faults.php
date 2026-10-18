<?php
/** Genuine PHP ZipArchive fault injections in disposable WordPress CI. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('WordPress/GF unavailable'); }
$root = getenv('BRC_LAB_DIR');
$data = json_decode(file_get_contents($root . '/independent.json'), true, 512, JSON_THROW_ON_ERROR);
$case = $data['cases']['one'];
$plan = GFBRC_Independent_Flat_Zip_Experiment::plan($case['form_id'], $case['entry_ids']);
$prefix = rtrim(get_temp_dir(), '/\\') . '/gfbrc-independent-flat*';
$before = glob($prefix) ?: [];
foreach ([
    ['BRC_IFZ_FAIL_ADD', '1', 'Injected add failure'],
    ['BRC_IFZ_FAIL_CLOSE', '1', 'finalization failed'],
    ['BRC_IFZ_CORRUPT', '1', 'unreadable'],
    ['BRC_IFZ_MUTATE_CLOSED', 'missing', 'count mismatch'],
    ['BRC_IFZ_MUTATE_CLOSED', 'extra', 'count mismatch'],
    ['BRC_IFZ_MUTATE_CLOSED', 'overwrite', 'integrity mismatch'],
] as [$key, $value, $message]) {
    putenv($key . '=' . $value);
    $caught = null;
    try {
        $result = GFBRC_Independent_Flat_Zip_Experiment::write_verified_zip($plan);
        @unlink($result);
    } catch (Throwable $e) { $caught = $e->getMessage(); }
    putenv($key);
    if (!is_string($caught) || stripos($caught, $message) === false) {
        throw new RuntimeException('Expected fault not rejected: ' . $key . '/' . $value . ' message=' . ($caught ?? 'none'));
    }
    if ((glob($prefix) ?: []) !== $before) {
        throw new RuntimeException('Temporary archive not cleaned after ' . $key . '/' . $value);
    }
    echo 'PASS_FAULT_REJECTED_AND_TEMP_CLEANED ' . $key . '=' . $value . ': ' . $caught . PHP_EOL;
}
