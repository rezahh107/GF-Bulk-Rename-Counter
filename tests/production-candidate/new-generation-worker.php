<?php
/**
 * Disposable genuine WordPress/GF/MariaDB race worker. Public operators never
 * call this file. Reflection only targets the real production transition seam.
 */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('Real GF runtime needed'); }
$lab = getenv('BRC_LAB_DIR');
$fixture_path = $lab . '/new-generation-fixture.json';
$action = getenv('BRC_RACE_ACTION');
$token = getenv('BRC_RACE_GENERATION');
$gate = getenv('BRC_RACE_GATE');
$cls = \GFBulkRename\New_Download::class;
$invoke = static function(string $method, ...$args) use ($cls) {
    return (new ReflectionMethod($cls, $method))->invoke(null, ...$args);
};
$guard = static function($ok, string $why): void {
    if (!$ok) { throw new RuntimeException($why); }
};
$barrier = static function () use ($gate, $lab): void {
    if ($gate === false || $gate === '') { return; }
    if (!str_starts_with($gate, $lab . '/') || !preg_match('~^[A-Za-z0-9_./-]+$~', $gate)) {
        throw new RuntimeException('Invalid race barrier path');
    }
    if (file_put_contents($gate . '.ready', (string)getmypid()) === false) {
        throw new RuntimeException('Race barrier marker failed');
    }
    $deadline = microtime(true) + 45;
    while (!is_file($gate . '.go')) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Race barrier timeout'); }
        usleep(20000);
    }
};
try {
    if ($action === 'setup') {
        $meta = json_decode(file_get_contents($lab . '/independent.json'), true, 512, JSON_THROW_ON_ERROR);
        $entry = GFAPI::get_entry((int)$meta['cases']['one']['entry_ids'][0]);
        $guard(is_array($entry) && is_string($entry['1'] ?? null), 'Missing real fixture file');
        $form = GFAPI::add_form([
            'title' => 'NEW deterministic pending-generation race',
            'fields' => [['id' => 1, 'type' => 'fileupload', 'label' => 'Upload']],
            'button' => ['type' => 'text', 'text' => 'Submit'],
        ]);
        $guard(!is_wp_error($form) && (int)$form > 0, 'Cannot create real race form');
        $id = GFAPI::add_entry(['form_id' => (int)$form, '1' => $entry['1']]);
        $zero = GFAPI::add_entry(['form_id' => (int)$form]);
        $guard(!is_wp_error($id) && !is_wp_error($zero), 'Cannot seed GF race Entry');
        $invoke('seed', (int)$form);
        $invoke('seed_pending', (int)$form);
        file_put_contents($fixture_path, wp_json_encode(['form' => (int)$form,
            'file_id' => (int)$id, 'zero_id' => (int)$zero]));
        $result = ['form' => (int)$form, 'file_id' => (int)$id, 'zero_id' => (int)$zero];
    } else {
        $fixture = json_decode(file_get_contents($fixture_path), true, 512, JSON_THROW_ON_ERROR);
        $form = (int)$fixture['form'];
        if ($action === 'state') {
            $coverage = $invoke('state', $invoke('raw', $form));
            $pending = $invoke('pending_decode', $invoke('pending_raw', $form));
            $result = ['rev' => $coverage['rev'], 'known' => $coverage['known'],
                'covered' => $coverage['covered'], 'id' => $pending['id'],
                'status' => $pending['status'], 'expires' => $pending['expires']];
        } elseif ($action === 'prepare') {
            $id = getenv('BRC_RACE_ZERO') === 'yes'
                ? (int)$fixture['zero_id'] : (int)$fixture['file_id'];
            $guard(is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token) === 1,
                'Unbounded race generation');
            $old = $invoke('raw', $form);
            $pending = $invoke('seed_pending', $form);
            $items = $invoke('plan', $form, [$id]);
            $op = ['v' => 2, 'id' => $token, 'user' => get_current_user_id(),
                'form' => $form, 'expires' => time() + 900,
                'base' => hash('sha256', $old), 'all' => [(int)$fixture['file_id'],
                    (int)$fixture['zero_id']], 'ids' => [$id],
                'files' => count($items), 'zero_entries' => count($items) ? 0 : 1,
                'fingerprint' => $invoke('fingerprint', $items), 'zip_sha' => '',
                'zero' => count($items) === 0, 'status' => 'prepared'];
            $barrier();
            $invoke('publish_prepared', $form, $old, $pending, $op);
            $result = ['generation' => $token, 'files' => count($items)];
        } elseif ($action === 'ready') {
            $op = $invoke('operation', $form, $token);
            $guard(!$op['zero'], 'Cannot ZIP zero-file operation');
            $plan = $invoke('plan', $form, $op['ids']);
            $guard(count($plan) === $op['files'] &&
                $invoke('fingerprint', $plan) === $op['fingerprint'],
                'Cannot verify same real file plan');
            $path = \GFBulkRename\Flat_Zip_Download::write_verified_zip($plan);
            try { $sha = hash_file('sha256', $path); }
            finally { @unlink($path); }
            $guard(is_string($sha) && strlen($sha) === 64, 'No verified ZIP identity');
            $barrier();
            $invoke('mark_ready', $form, $token, $sha);
            $result = ['generation' => $token, 'sha256' => $sha];
        } elseif ($action === 'ack') {
            $op = $invoke('operation', $form, $token);
            $barrier();
            $result = ['result' => $invoke('commit_ack', $form, $token, $op)];
        } elseif ($action === 'expire') {
            global $wpdb;
            $op = $invoke('pending_decode', $invoke('pending_raw', $form));
            $op['expires'] = time() - 1;
            $changed = $wpdb->update($wpdb->options,
                ['option_value' => wp_json_encode($op)],
                ['option_name' => 'gfbrc_new_pending_' . $form]);
            $guard($changed === 1, 'Expiration fixture update failed');
            $result = ['expired' => $op['id']];
        } elseif ($action === 'external_revision') {
            global $wpdb;
            $raw = $invoke('raw', $form);
            $coverage = $invoke('state', $raw);
            $coverage['rev']++;
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
                wp_json_encode($coverage), 'gfbrc_new_coverage_' . $form, $raw));
            $guard($changed === 1, 'Concurrent coverage writer did not advance revision');
            $result = ['rev' => $coverage['rev']];
        } else { throw new RuntimeException('Unknown race-worker action'); }
    }
    echo 'PASS_REAL_NEW_GENERATION_WORKER ' . wp_json_encode($result) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'EXPECTED_OR_DIAGNOSTIC_NEW_RACE_REJECTION ' . $e->getMessage() . PHP_EOL);
    exit(42);
}
