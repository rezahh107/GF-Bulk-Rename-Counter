<?php
/**
 * Non-production qualification of exact NEW coverage history in genuine
 * WordPress/MariaDB. Identities/acknowledgments are controlled fixtures.
 * No browser receipt, production operation record, or GF import is implied.
 */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Real WordPress and Gravity Forms required');
}

final class GFBRC_New_Coverage_Stress {
    private const MAX_TEST_BYTES = 1048576;
    private static function check(bool $ok, string $message): void {
        if (!$ok) { throw new RuntimeException($message); }
    }
    private static function key(int $form): string {
        return 'gfbrc_nonproduction_coverage_stress_' . $form;
    }
    private static function encode(array $state): string {
        return (string) wp_json_encode($state, JSON_THROW_ON_ERROR);
    }
    private static function raw(int $form): string {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::key($form)
        ));
        self::check(is_string($value) && $value !== '', 'Option missing');
        return $value;
    }
    private static function decode(string $json): array {
        $s = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::check(is_array($s) && isset($s['revision'], $s['ranges'])
            && is_int($s['revision']) && $s['revision'] >= 0
            && is_array($s['ranges']), 'Invalid coverage schema');
        $prev = 0;
        foreach ($s['ranges'] as $range) {
            self::check(is_array($range) && count($range) === 2
                && is_int($range[0]) && is_int($range[1])
                && $range[0] > $prev && $range[0] <= $range[1],
                'Overlapping, unsorted or invalid coverage ranges');
            $prev = $range[1];
        }
        return $s;
    }
    /** Lossless union, without expanding acknowledged contiguous intervals. */
    private static function union(array $ranges, array $new_ids): array {
        foreach ($new_ids as $id) {
            self::check(is_int($id) && $id > 0, 'Invalid incoming identity');
            $ranges[] = [$id, $id];
        }
        usort($ranges, static function ($a, $b) { return $a[0] <=> $b[0]; });
        $out = [];
        foreach ($ranges as $range) {
            $n = count($out) - 1;
            if ($n >= 0 && $range[0] <= $out[$n][1] + 1) {
                $out[$n][1] = max($out[$n][1], $range[1]);
            } else {
                $out[] = $range;
            }
        }
        return $out;
    }
    private static function contains(array $ranges, int $id): bool {
        $lo = 0; $hi = count($ranges) - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($id < $ranges[$mid][0]) { $hi = $mid - 1; }
            elseif ($id > $ranges[$mid][1]) { $lo = $mid + 1; }
            else { return true; }
        }
        return false;
    }
    private static function clear(int $form): void {
        wp_cache_delete(self::key($form), 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
    }
    private static function cas(int $form, string $expected, array $new_ids): bool {
        global $wpdb;
        $old = self::decode($expected);
        $next = [
            'revision' => $old['revision'] + 1,
            'ranges' => self::union($old['ranges'], $new_ids),
        ];
        $encoded = self::encode($next);
        self::check(strlen($encoded) <= self::MAX_TEST_BYTES, 'Bounded option-size guard');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s
             WHERE option_name = %s AND BINARY option_value = BINARY %s",
            $encoded, self::key($form), $expected
        ));
        self::clear($form);
        self::check($updated !== false, 'Database CAS query failed');
        $current = self::raw($form);
        self::check(get_option(self::key($form)) === $current,
            'WordPress option-cache readback does not match MariaDB');
        if ($updated === 1) {
            self::check($current === $encoded, 'CAS readback mismatch');
            return true;
        }
        self::check($updated === 0, 'Unexpected CAS update count');
        return false;
    }
    private static function reset(int $form): void {
        global $wpdb;
        delete_option(self::key($form));
        self::check(add_option(self::key($form), self::encode([
            'revision' => 0, 'ranges' => [],
        ]), '', false), 'Could not seed nonautoload option');
        $autoload = $wpdb->get_var($wpdb->prepare(
            "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
            self::key($form)
        ));
        self::check(!in_array((string) $autoload, ['yes', 'on', 'auto', 'auto-on'], true),
            'Coverage history accidentally autoloaded');
    }
    public static function run(): void {
        $form1 = 987654321; $form2 = 987654322;
        self::reset($form1); self::reset($form2);
        try {
            // Two tabs have genuinely distinct pre-read old-value snapshots.
            // This verifies DB CAS conflict; independent OS-process races are
            // already exercised by PR #18, not reproduced by this script.
            $stale = self::raw($form1);
            self::check(self::cas($form1, $stale, [101, 103, 105]), 'First ACK failed');
            self::check(!self::cas($form1, $stale, [102]), 'Stale ACK overwrote state');
            $after = self::decode(self::raw($form1));
            self::check($after['revision'] === 1
                && $after['ranges'] === [[101, 101], [103, 103], [105, 105]],
                'Stale ACK modified exact coverage');
            self::check(self::cas($form1, self::raw($form1), [102, 104]),
                'Fresh replay failed to merge the identity holes');
            $after = self::decode(self::raw($form1));
            self::check($after['ranges'] === [[101, 105]] && $after['revision'] === 2,
                'Lossless hole reconciliation failed');
            self::check(self::contains($after['ranges'], 101)
                && !self::contains($after['ranges'], 99), 'Coverage membership incorrect');
            self::check(self::decode(self::raw($form2))['ranges'] === [],
                'Per-form isolation violated');
            // Real MariaDB option-value storage, with worst-case noncontiguous
            // synthetic identities (one interval per acknowledged Entry).
            // Measurements DO NOT establish physical real-GF Entry count limits.
            $sizes = [];
            foreach ([1000, 5000, 20000] as $count) {
                $ids = [];
                for ($i = 0; $i < $count; $i++) { $ids[] = 200001 + 2 * $i; }
                $start = microtime(true);
                $initial = self::encode([
                    'revision' => $count, 'ranges' => self::union([], $ids),
                ]);
                self::check(strlen($initial) <= self::MAX_TEST_BYTES,
                    'Fragmented history exceeds test safety ceiling');
                global $wpdb;
                $result = $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
                    $initial, self::key($form2)
                ));
                self::check($result === 1, 'Could not persist fragmented history');
                self::clear($form2);
                $db = self::raw($form2);
                $parsed = self::decode($db);
                self::check($db === $initial && count($parsed['ranges']) === $count,
                    'Fragmented history was altered in persistence');
                self::check(self::contains($parsed['ranges'], 200001)
                    && self::contains($parsed['ranges'], 200001 + 2 * ($count - 1))
                    && !self::contains($parsed['ranges'], 200002),
                    'Fragmented membership oracle mismatch');
                self::check(get_option(self::key($form2)) === $db,
                    'Fragmented history cache mismatch');
                $sizes[(string)$count] = [
                    'bytes' => strlen($db),
                    'elapsed_ms' => (int)round((microtime(true) - $start) * 1000),
                    'peak_memory_bytes' => memory_get_peak_usage(true),
                ];
            }
            // Prove fail-closed parsing, not silent repair, on corrupt storage.
            $wpdb->update($wpdb->options, ['option_value' => '{"revision":1,"ranges":[[3,3],[2,2]]}'],
                ['option_name' => self::key($form2)]);
            self::clear($form2);
            $rejected = false;
            try { self::decode(self::raw($form2)); }
            catch (RuntimeException $e) { $rejected = true; }
            self::check($rejected, 'Corrupted ranges were accepted');
            echo 'PASS_REAL_WP_MARIADB_FRAGMENTED_EXACT_COVERAGE '
                . wp_json_encode(['sizes' => $sizes, 'CAS_stale_rejected' => true,
                    'per_form_isolation' => true, 'corrupt_rejected' => true,
                    'actual_browser_ack' => 'NOT_EXECUTED',
                    'production_lifecycle' => 'NOT_PROVEN']) . PHP_EOL;
        } finally {
            delete_option(self::key($form1));
            delete_option(self::key($form2));
        }
    }
}
GFBRC_New_Coverage_Stress::run();
