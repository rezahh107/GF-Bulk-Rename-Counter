<?php
/** Disposable checkpoint experiment. NEVER loaded by released GFBRC. */
if (!defined('ABSPATH')) { throw new RuntimeException('Real WordPress required'); }

function cq_assert(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function cq_key(int $form): string {
    cq_assert($form > 0, 'Invalid form ID');
    return 'gfbrc_iq_only_confirmed_' . $form;
}
function cq_encode(array $value): string {
    return (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function cq_db_raw(int $form): string {
    global $wpdb;
    $raw = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", cq_key($form)
    ));
    cq_assert(is_string($raw) && $raw !== '', 'Test state is missing or unreadable');
    return $raw;
}
function cq_decode(string $value): array {
    $state = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    cq_assert(is_array($state) && isset($state['frontier'], $state['rev'], $state['last_op']) &&
        is_int($state['frontier']) && is_int($state['rev']) && is_string($state['last_op']),
        'Unexpected state schema');
    return $state;
}
function cq_reset(int $form, int $frontier = 100): array {
    // Test-only disposable key, not any actual GFBRC option.
    $key = cq_key($form);
    delete_option($key);
    $initial = ['frontier' => $frontier, 'rev' => 0, 'last_op' => ''];
    cq_assert(add_option($key, cq_encode($initial), '', false), 'Cannot seed nonautoload option');
    global $wpdb;
    $autoload = $wpdb->get_var($wpdb->prepare(
        "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $key
    ));
    cq_assert(!in_array((string)$autoload, ['yes','on','auto','auto-on'], true),
        'Test option must not be autoloaded');
    return ['value'=>$initial, 'autoload'=>$autoload];
}
function cq_validate(array $snapshot, string $ack, int $form, array $old): string {
    if (empty($snapshot['id']) || !is_string($snapshot['id']) ||
        $ack !== $snapshot['id']) { return 'NO_ACK'; }
    if (($snapshot['form'] ?? null) !== $form ||
        ($snapshot['base_rev'] ?? null) !== $old['rev'] ||
        ($snapshot['base_frontier'] ?? null) !== $old['frontier']) { return 'STALE'; }
    $expected = $snapshot['expected'] ?? null;
    $included = $snapshot['included'] ?? null;
    $target = $snapshot['target'] ?? null;
    if (!is_array($expected) || !is_array($included) ||
        !$expected || !is_int($target) || $target <= $old['frontier']) {
        return 'INCOMPLETE';
    }
    $normalized = static function(array $values): ?array {
        foreach ($values as $value) { if (!is_int($value) || $value < 1) { return null; } }
        if (count(array_unique($values)) !== count($values)) { return null; }
        sort($values, SORT_NUMERIC);
        return $values;
    };
    $a = $normalized($expected); $b = $normalized($included);
    if (!$a || !$b || $a !== $b || max($a) !== $target ||
        min($a) <= $old['frontier']) { return 'INCOMPLETE'; }
    // The independently provided expected IDs and file occurrences are only a
    // trusted synthetic oracle in this lab; NOT GF snapshot-completeness proof.
    $expected_files = $snapshot['expected_files'] ?? null;
    $zip_members = $snapshot['zip_members'] ?? null;
    $file_free = $snapshot['file_free'] ?? null;
    if (!is_array($expected_files) || !is_array($zip_members) || !is_array($file_free)) {
        return 'INCOMPLETE';
    }
    foreach ($file_free as $id) {
        if (!is_int($id) || !in_array($id, $a, true) || in_array($id, $expected_files, true)) {
            return 'INCOMPLETE';
        }
    }
    if (count(array_unique($file_free)) !== count($file_free)) { return 'INCOMPLETE'; }
    sort($expected_files, SORT_STRING); sort($zip_members, SORT_STRING);
    if ($expected_files !== $zip_members ||
        (!empty($expected_files) && empty($snapshot['zip_verified']))) { return 'INCOMPLETE'; }
    return 'VALID';
}
function cq_commit(int $form, array $snapshot, string $ack, ?string $read_before_barrier = null): array {
    global $wpdb;
    $old_raw = $read_before_barrier ?? cq_db_raw($form);
    $old = cq_decode($old_raw);
    $reason = cq_validate($snapshot, $ack, $form, $old);
    if ($reason !== 'VALID') {
        if ($old['last_op'] === $ack && $ack !== '' && $reason === 'STALE') {
            return ['outcome'=>'DUPLICATE', 'state'=>$old, 'affected'=>0];
        }
        return ['outcome'=>$reason, 'state'=>$old, 'affected'=>0];
    }
    $next = ['frontier'=>$snapshot['target'], 'rev'=>$old['rev'] + 1,
        'last_op'=>$snapshot['id']];
    $next_raw = cq_encode($next);
    // A single InnoDB statement supplies atomic compare-and-swap.
    // BINARY prevents a case-insensitive collation from broadening equality.
    $changed = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options}
         SET option_value = %s
         WHERE option_name = %s AND BINARY option_value = BINARY %s",
         $next_raw, cq_key($form), $old_raw
    ));
    if ($changed === false) { return ['outcome'=>'DB_FAILURE', 'state'=>$old, 'affected'=>0]; }
    if ($changed !== 1) {
        // No optimism from cached get_option: read authoritative DB state.
        $current = cq_decode(cq_db_raw($form));
        return ['outcome'=>'CAS_CONFLICT', 'state'=>$current, 'affected'=>$changed];
    }
    wp_cache_delete(cq_key($form), 'options');
    wp_cache_delete('notoptions', 'options');
    wp_cache_delete('alloptions', 'options'); // defensive; option is explicitly non-autoload.
    $api_raw = get_option(cq_key($form));
    cq_assert($api_raw === $next_raw && cq_db_raw($form) === $next_raw,
        'DB and WordPress API disagree after commit');
    return ['outcome'=>'COMMITTED', 'state'=>$next, 'affected'=>$changed];
}
function cq_snapshot(int $form, string $op, int $start, int $target,
                     array $expected, ?array $included=null, array $file_free=[]): array {
    $included ??= $expected;
    $files = [];
    foreach ($expected as $id) {
        if (!in_array($id,$file_free,true)) { $files[] = 'f-'.$id; }
    }
    return [
        'id'=>$op,'form'=>$form,'base_frontier'=>$start,'base_rev'=>0,
        'target'=>$target,'expected'=>$expected,'included'=>$included,
        'expected_files'=>$files,'zip_members'=>$files,
        'file_free'=>$file_free,'zip_verified'=>true,
    ];
}
