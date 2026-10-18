<?php
/** Install experiment-only third-party hooks, then call the real native action. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Native GF required');
}
$experiment = getenv('BRC_MC_SCENARIO');
if (!$experiment || !preg_match('/^[a-z0-9_]+$/D', $experiment)) {
    throw new RuntimeException('Unrecognized experimental scenario');
}
if ($experiment === 'mc_late_filter') {
    add_filter('bdfgf_entry_filename', static fn($path) => 'forced-same.jpg', 999, 3);
}
if (str_starts_with($experiment, 'mc_hook_')) {
    add_action('bdfgf_after_uploaded_files', static function ($zip) use ($experiment) {
        if (!$zip instanceof ZipArchive) {
            throw new RuntimeException('Final hook did not receive native ZipArchive');
        }
        $lab = getenv('BRC_LAB_DIR');
        $plan = json_decode(file_get_contents($lab . '/mc-' . $experiment . '.json'), true);
        if (empty($plan['occurrences'])) {
            throw new RuntimeException('No recorded primary provenance before final hook');
        }
        $first = $plan['occurrences'][0];
        if ($experiment === 'mc_hook_extra' || $experiment === 'mc_hook_unregistered') {
            $ok = $zip->addFromString('extra-member.txt', 'unregistered posthook content');
        } elseif ($experiment === 'mc_hook_overwrite') {
            $ok = $zip->addFromString($first['member'], 'changed by final hook');
        } elseif ($experiment === 'mc_hook_same_bytes') {
            // Indistinguishable post-hook replacement: hash/count/path all match.
            // This is a provenance falsification, NEVER an admissible PASS.
            $ok = $zip->addFile($first['source'], $first['member']);
        } else {
            throw new RuntimeException('Unknown synthetic final-hook case');
        }
        if (!$ok) {
            throw new RuntimeException('Final hook operation failed');
        }
        file_put_contents($lab . '/mc-hook-' . $experiment . '.txt', 'invoked');
    }, 10, 3);
}
require __DIR__ . '/../integration/download.php';
