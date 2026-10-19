<?php
/**
 * Real WordPress/GF qualification of Legacy naming vs the independent plan.
 * This is a comparison only: Owner has NOT admitted any collision suffix policy.
 */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Real WordPress and Gravity Forms required');
}
$root = getenv('BRC_LAB_DIR');
$fixture = json_decode(file_get_contents($root . '/independent.json'), true, 512, JSON_THROW_ON_ERROR);
$parity = 0;
$collision_deltas = 0;
$examples = [];
foreach (['one', 'zero_counter', 'empty_counter', 'missing_counter',
          'malformed_counter', 'repeated_physical', 'n99', 'sanitized_equal',
          'shared', 'host_folder_collision'] as $scenario) {
    $args = $fixture['cases'][$scenario];
    $plan = \GFBulkRename\Flat_Zip_Download::plan(
        (int)$args['form_id'], array_map('intval', $args['entry_ids'])
    );
    // Real active Legacy handler, new object for each independent download.
    $legacy = new \GFBulkRename\Rename_Handler();
    $recheck = \GFBulkRename\Flat_Zip_Download::plan(
        (int)$args['form_id'], array_map('intval', $args['entry_ids'])
    );
    if (array_column($plan, 'name') !== array_column($recheck, 'name')) {
        throw new RuntimeException('Experimental collision planner not deterministic');
    }
    foreach ($plan as $item) {
        $incoming_basename = sanitize_file_name(wp_basename($item['source']));
        $old = $legacy->rename_by_counter(
            $incoming_basename, (int)$item['entry_id'], $item['source']
        );
        if (in_array($scenario, ['one', 'zero_counter', 'empty_counter',
                                 'missing_counter', 'malformed_counter'], true)) {
            if (count($plan) !== 1 || $item['name'] !== $old) {
                throw new RuntimeException('Legacy singleton name changed: ' . $scenario .
                    ' expected=' . $old . ' independent=' . $item['name']);
            }
            $parity++;
        } elseif ($old !== $item['name']) {
            $collision_deltas++;
            if (count($examples) < 4) {
                $examples[] = ['case' => $scenario, 'legacy' => $old,
                               'experiment' => $item['name']];
            }
        }
    }
}
if ($parity !== 5) {
    throw new RuntimeException('Expected Legacy singleton parity');
}
// Legacy pass-through preserves the original host-supplied entry_filename,
// including host subfolders. Flat ZIP cannot blindly preserve that folder.
$fallback = $fixture['cases']['zero_counter'];
$id = (int)$fallback['entry_ids'][0];
$old = (new \GFBulkRename\Rename_Handler())->rename_by_counter(
    'host-folder/keep-original.jpg', $id, '/uploads/keep-original.jpg'
);
if ($old !== 'host-folder/keep-original.jpg') {
    throw new RuntimeException('Legacy pass-through input behavior changed');
}
echo 'PASS_REAL_LEGACY_NONCOLLIDING_NAMES parity=' . $parity . PHP_EOL;
echo 'PASS_LEGACY_VISUAL_COLLISION_NAMING delta_count=' . $collision_deltas .
    ' examples=' . wp_json_encode($examples) . PHP_EOL;
echo 'PASS_LEGACY_PASS_THROUGH_RETAINS_HOST_PATH; flat ZIP must intentionally strip it' . PHP_EOL;
