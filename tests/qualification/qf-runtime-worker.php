<?php
/**
 * QF-01/QF-02 independent runtime worker.
 * Uses the authentic installed WordPress/GF runtime via separate WP-CLI
 * processes. All forms/entries are synthetic, in a disposable CI database.
 */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Authentic WordPress and Gravity Forms required');
}
$lab = getenv('BRC_LAB_DIR');
$action = (string)getenv('BRC_QF_ACTION');
$case = (string)getenv('BRC_QF_CASE');
$fixture_file = $lab . '/qf-runtime-fixtures.json';
$cls = \GFBulkRename\New_Download::class;
$call = static function(string $method, ...$args) use ($cls) {
    return (new ReflectionMethod($cls, $method))->invoke(null, ...$args);
};
$assert = static function($truth, string $msg): void {
    if (!$truth) { throw new RuntimeException($msg); }
};
$original = json_decode(file_get_contents($lab . '/independent.json'), true, 512, JSON_THROW_ON_ERROR);
$old = GFAPI::get_entry((int)$original['cases']['one']['entry_ids'][0]);
$other = GFAPI::get_entry((int)$original['cases']['multiple']['entry_ids'][0]);
$source_a = (string)($old['1'] ?? '');
$source_b = (string)($other['1'] ?? '');
$assert($source_a !== '' && $source_b !== '' && $source_a !== $source_b, 'Two distinct valid uploaded sources required');
$fixture = is_file($fixture_file)
    ? json_decode(file_get_contents($fixture_file), true, 512, JSON_THROW_ON_ERROR) : [];
$entry_table = \GFFormsModel::get_entry_table_name();
global $wpdb;
$assert((bool)preg_match('/^[a-zA-Z0-9_]+$/', $entry_table), 'Unexpected GF Entry table name');
$engine = static function(string $table) use ($wpdb): string {
    return (string)$wpdb->get_var($wpdb->prepare(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table));
};
$save = static function() use (&$fixture, $fixture_file): void {
    file_put_contents($fixture_file, wp_json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
};
$create = static function(string $key, bool $file) use (&$fixture, $source_a, $assert, $call, $save): array {
    $form = GFAPI::add_form([
        'title' => 'QF isolated ' . $key,
        'fields' => [['id' => 1, 'type' => 'fileupload', 'label' => 'Authentic upload']],
        'button' => ['type' => 'text', 'text' => 'Submit'],
    ]);
    $assert(!is_wp_error($form) && (int)$form > 0, 'Cannot create QF form');
    $entry = GFAPI::add_entry(['form_id' => (int)$form, '1' => $file ? $source_a : '']);
    $assert(!is_wp_error($entry) && (int)$entry > 0, 'Cannot create QF entry');
    $fixture[$key] = ['form' => (int)$form, 'entry' => (int)$entry, 'initial_file' => $file];
    $call('seed', (int)$form);
    $call('seed_pending', (int)$form);
    $save();
    return $fixture[$key];
};
try {
    if ($action === 'init') {
        $assert($engine($wpdb->options) === 'InnoDB', 'wp_options must be InnoDB');
        $assert($engine($entry_table) === 'InnoDB', 'GF Entry table must begin InnoDB');
        $create('snapshot_innodb', true);
        $create('snapshot_myisam', true);
        $create('snapshot_myisam_idle', true);
        foreach (['file_pre' => true, 'file_during' => true, 'file_post' => true,
                  'zero_pre' => false, 'zero_during' => false, 'zero_post' => false] as $key=>$file) {
            $create($key, $file);
        }
        $result = ['forms' => $fixture, 'options_engine' => $engine($wpdb->options),
            'gf_entry_engine' => $engine($entry_table)];
    } else {
        $assert(isset($fixture[$case]) && is_array($fixture[$case]), 'Unknown QF fixture case');
        $form = (int)$fixture[$case]['form'];
        $id = (int)$fixture[$case]['entry'];
        $key = 'gfbrc_new_coverage_' . $form;
        if ($action === 'prepare') {
            $raw = $call('raw', $form);
            $pending = $call('seed_pending', $form);
            $plan = $call('plan', $form, [$id]);
            $op = [
                'v' => 2, 'id' => bin2hex(random_bytes(16)),
                'user' => get_current_user_id(), 'form' => $form,
                'expires' => time() + 900, 'base' => hash('sha256', $raw),
                'all' => [$id], 'ids' => [$id],
                'files' => count($plan), 'zero_entries' => count($plan) === 0 ? 1 : 0,
                'fingerprint' => $call('fingerprint', $plan),
                'zip_sha' => '', 'zero' => !$plan, 'status' => 'prepared'
            ];
            $call('publish_prepared', $form, $raw, $pending, $op);
            if ($plan) {
                $path = \GFBulkRename\Flat_Zip_Download::write_verified_zip($plan);
                try { $sha = hash_file('sha256', $path); }
                finally { @unlink($path); }
                $assert(is_string($sha) && strlen($sha) === 64, 'Actual verified ZIP failed');
                $call('mark_ready', $form, $op['id'], $sha);
                $op['zip_sha'] = $sha;
            }
            $fixture[$case]['operation'] = $op['id'];
            $fixture[$case]['zip_sha'] = $op['zip_sha'];
            $save();
            $result = ['op' => $op['id'], 'files' => count($plan), 'zip_sha' => $op['zip_sha']];
        } elseif ($action === 'mutate') {
            $before = GFAPI::get_entry($id);
            $assert(is_array($before), 'Missing actual GF Entry');
            $ok = GFAPI::update_entry_field($id, 1, $source_b);
            $assert(!is_wp_error($ok) && $ok !== false, 'Authentic GFAPI update_entry_field failed');
            $after = GFAPI::get_entry($id);
            $assert(is_array($after) && ($after['1'] ?? '') === $source_b, 'GFAPI mutation readback failed');
            $result = ['entry' => $id, 'before' => $before['1'] ?? '',
                'after' => $after['1'], 'different' => ($before['1'] ?? '') !== $after['1']];
        } elseif ($action === 'status_mutate') {
            $before = GFAPI::get_entry($id);
            $assert(is_array($before) && ($before['status'] ?? null) === 'active', 'Snapshot source not active');
            $ok = GFAPI::update_entry_property($id, 'status', 'spam');
            $assert(!is_wp_error($ok) && $ok !== false, 'GFAPI status mutation failed');
            $after = GFAPI::get_entry($id);
            $assert(is_array($after) && ($after['status'] ?? null) === 'spam', 'Status mutation not persisted');
            $result = ['entry' => $id, 'before' => $before['status'],
                'after' => $after['status'], 'engine' => $engine($entry_table)];
        } elseif ($action === 'snapshot') {
            $raw = $call('raw', $form);
            [$all, $new] = $call('snapshot', $form, $call('state', $raw));
            $result = ['all' => $all, 'new' => $new, 'engine' => $engine($entry_table)];
        } elseif ($action === 'guard') {
            $assert($engine($wpdb->options) === 'InnoDB', 'Options engine must be InnoDB');
            $call('begin_operation_transaction');
            $assert($wpdb->query('ROLLBACK') !== false, 'Rollback failed');
            $result = ['options' => $engine($wpdb->options), 'entry' => $engine($entry_table),
                'guard_accepted' => true];
        } elseif ($action === 'ack') {
            $assert(isset($fixture[$case]['operation']), 'ACK requires actual prepare');
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['form_id' => (string)$form,
                '_wpnonce' => wp_create_nonce('gfbrc_new_' . $form),
                'operation' => $fixture[$case]['operation'],
                'confirmed' => 'yes', 'zero_reviewed' => 'yes',
                'sha256' => $fixture[$case]['zip_sha']];
            // Executes the actual public handler. wp_send_json exits the process,
            // producing the real HTTP-style response envelope and error status.
            \GFBulkRename\New_Download::ack();
            throw new RuntimeException('ACK unexpectedly returned without wp_send_json');
        } elseif ($action === 'state') {
            $raw = $call('raw', $form);
            $state = $call('state', $raw);
            $latest = GFAPI::get_entry($id);
            $next = $call('snapshot', $form, $state);
            $result = [
                'rev' => $state['rev'], 'known' => $state['known'],
                'covered' => $state['covered'], 'entry' => $id,
                'upload' => $latest['1'] ?? null, 'status' => $latest['status'] ?? null,
                'next_new' => $next[1], 'engine' => $engine($entry_table)
            ];
        } else { throw new RuntimeException('Unsupported QF action ' . $action); }
    }
    echo 'QF_WORKER_RESULT ' . wp_json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'QF_WORKER_ERROR ' . $e->getMessage() . PHP_EOL);
    exit(42);
}
