<?php
/** Emit the writer plan solely as an independent HTTP fixture reference. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('Native WP/GF required'); }
$root = getenv('BRC_LAB_DIR');
$data = json_decode(file_get_contents($root . '/independent.json'), true, 512, JSON_THROW_ON_ERROR);
$plans = [];
foreach ($data['cases'] as $name => $case) {
    try {
        $plan = \GFBulkRename\Flat_Zip_Download::plan($case['form_id'], $case['entry_ids']);
        $plans[$name] = array_map(function($p) {
            return ['entry_id' => $p['entry_id'], 'field_id' => $p['field_id'], 'url' => $p['url'],
                'source' => $p['source'], 'name' => $p['name'], 'sha256' => $p['sha256'], 'size' => $p['size']];
        }, $plan);
    } catch (Throwable $e) { $plans[$name] = ['error' => $e->getMessage()]; }
}
file_put_contents($root . '/independent-plans.json', wp_json_encode($plans, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo 'Independent GF plans for ' . count($plans) . ' scenarios prepared.' . PHP_EOL;
