<?php
/**
 * Whole-form, history-independent ALL selection. Does not read or write NEW state.
 *
 * @package GFBulkRename
 */
namespace GFBulkRename;

use RuntimeException;
use Throwable;

if (!defined('ABSPATH')) { exit; }

final class All_Download {
    private const TTL = 600;
    private const BATCH = 200;

    public static function register(): void {
        add_action('wp_ajax_gfbrc_all_prepare', [__CLASS__, 'prepare']);
        add_action('admin_footer', [__CLASS__, 'footer']);
    }

    private static function authorize(): int {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
            !\GFCommon::current_user_can_any('gravityforms_view_entries')) {
            throw new RuntimeException('ALL requires authenticated Entry viewing permission');
        }
        $raw = $_POST['form_id'] ?? null;
        if (!is_string($raw) || !ctype_digit($raw) ||
            (string)(int)$raw !== $raw || (int)$raw < 1) {
            throw new RuntimeException('Invalid ALL form scope');
        }
        $form = (int)$raw;
        $nonce = $_POST['_wpnonce'] ?? null;
        if (!is_string($nonce) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), 'gfbrc_independent_flat_zip_' . $form)) {
            throw new RuntimeException('Invalid ALL request nonce');
        }
        if (!is_array(\GFAPI::get_form($form))) {
            throw new RuntimeException('ALL form is unavailable');
        }
        return $form;
    }

    /** Fail closed if GF identity/field metadata cannot share a consistent snapshot. */
    private static function storage(): void {
        global $wpdb;
        if (!class_exists('\GFFormsModel') ||
            !method_exists('\GFFormsModel', 'get_entry_table_name') ||
            !method_exists('\GFFormsModel', 'get_entry_meta_table_name')) {
            throw new RuntimeException('Transactional Gravity Forms storage unavailable');
        }
        foreach ([\GFFormsModel::get_entry_table_name(),
                  \GFFormsModel::get_entry_meta_table_name()] as $table) {
            if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table) ||
                $wpdb->get_var($wpdb->prepare(
                    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                    $table
                )) !== 'InnoDB') {
                throw new RuntimeException('ALL requires transactional InnoDB Entry and field storage');
            }
        }
    }

    private static function budget(int $entries, int $bytes, float $start): void {
        $limit = trim((string)ini_get('memory_limit'));
        if ($limit !== '' && $limit !== '-1' && preg_match('/^([0-9]+)([KMG]?)$/i', $limit, $m)) {
            $multiplier = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($m[2])];
            $available = (float)$m[1] * $multiplier - memory_get_usage(true);
            // Admission allowance, not a promise about ZIP compression or hosting throughput.
            if ($available < 16777216 + $entries * 8192) {
                throw new RuntimeException('Insufficient PHP memory headroom for complete ALL; no ZIP generated');
            }
        }
        $max = (int)ini_get('max_execution_time');
        if ($max > 0 && microtime(true) - $start >= max(1, $max - 5)) {
            throw new RuntimeException('Insufficient PHP execution-time budget for complete ALL');
        }
        if ($bytes > 0) {
            $free = disk_free_space(get_temp_dir());
            if ($free === false || $free < $bytes + 16777216) {
                throw new RuntimeException('Insufficient temporary disk space for complete ALL ZIP');
            }
        }
    }

    /**
     * Obtain every active ID in one consistent InnoDB read view, regardless of
     * Entry List search, pages, checked rows, or NEW coverage. No 500-row clip.
     */
    private static function ids(int $form, float $start): array {
        global $wpdb;
        self::storage();
        if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false ||
            $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT') === false) {
            throw new RuntimeException('ALL consistent selection snapshot unavailable');
        }
        $committed = false;
        try {
            $criteria = ['status' => 'active'];
            $ids = []; $seen = []; $total = null;
            $offset = 0;
            do {
                self::budget($offset, 0, $start);
                $part_total = 0;
                $part = \GFAPI::get_entry_ids($form, $criteria,
                    ['key' => 'id', 'direction' => 'ASC', 'is_numeric' => true],
                    ['offset' => $offset, 'page_size' => self::BATCH], $part_total);
                if (!is_array($part) || !is_numeric($part_total) ||
                    (int)$part_total < 0 || (float)(int)$part_total != (float)$part_total ||
                    ($total !== null && $total !== (int)$part_total)) {
                    throw new RuntimeException('ALL Entry enumeration count changed or failed');
                }
                $total = (int)$part_total;
                foreach ($part as $id) {
                    $id = (int)$id;
                    if ($id < 1 || isset($seen[$id]) ||
                        ($ids && $id <= $ids[count($ids) - 1])) {
                        throw new RuntimeException('ALL selection has duplicate or unsorted Entry IDs');
                    }
                    $seen[$id] = true;
                    $ids[] = $id;
                }
                $offset += count($part);
                if (!$part || count($part) < self::BATCH) { break; }
                if ($offset > $total) {
                    throw new RuntimeException('ALL Entry enumeration overflow');
                }
            } while ($offset < $total);
            if ($offset !== $total || count($seen) !== $total) {
                throw new RuntimeException('ALL selection incomplete; no partial ZIP');
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('ALL selection snapshot commit failed');
            }
            $committed = true;
            return $ids;
        } finally {
            if (!$committed) { $wpdb->query('ROLLBACK'); }
        }
    }

    private static function plan(int $form, array $ids): array {
        if (!$ids) { return []; }
        try {
            return Flat_Zip_Download::plan($form, $ids);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'No eligible files found') { return []; }
            throw $e;
        }
    }

    private static function manifest(int $form, bool $admit_zip_disk = true): array {
        $start = microtime(true);
        $ids = self::ids($form, $start);
        self::budget(count($ids), 0, $start);
        $plan = self::plan($form, $ids);
        $covered = [];
        $bytes = 0;
        $parts = [];
        foreach ($plan as $item) {
            $covered[(int)$item['entry_id']] = true;
            if ((int)$item['size'] > PHP_INT_MAX - $bytes) {
                throw new RuntimeException('ALL source size exceeds supported integer range');
            }
            $bytes += (int)$item['size'];
            $parts[] = [(int)$item['entry_id'], (string)$item['field_id'],
                (string)$item['url'], (string)$item['name'],
                (int)$item['size'], (string)$item['sha256']];
        }
        // A final consistency check does not create another ZIP. Recheck
        // memory/time without requiring space for a second archive while
        // the first verified ZIP is still held in temporary storage.
        self::budget(count($ids), $admit_zip_disk ? $bytes : 0, $start);
        // Re-evaluate active membership after the potentially slow source plan.
        if (self::ids($form, $start) !== $ids) {
            throw new RuntimeException('ALL form membership changed during preparation');
        }
        return [
            'ids' => $ids, 'plan' => $plan,
            'entries' => count($ids), 'files' => count($plan),
            'zero_entries' => count($ids) - count($covered),
            'ids_hash' => hash('sha256', wp_json_encode($ids)),
            'plan_hash' => hash('sha256', wp_json_encode($parts, JSON_UNESCAPED_SLASHES)),
        ];
    }

    private static function sign(string $json): string {
        return hash_hmac('sha256', 'gfbrc-all-v1|' . $json, wp_salt('auth'));
    }

    public static function prepare(): void {
        try {
            $form = self::authorize();
            $result = self::manifest($form);
            $payload = [
                'v' => 1, 'form' => $form, 'user' => get_current_user_id(),
                'issued' => time(),
                'entries' => $result['entries'], 'files' => $result['files'],
                'zero_entries' => $result['zero_entries'],
                'ids_hash' => $result['ids_hash'], 'plan_hash' => $result['plan_hash'],
            ];
            $json = wp_json_encode($payload);
            if (!is_string($json)) { throw new RuntimeException('ALL preview cannot be encoded'); }
            wp_send_json_success([
                'entries' => $result['entries'], 'files' => $result['files'],
                'zero_entries' => $result['zero_entries'], 'token' => $json,
                'seal' => self::sign($json),
            ]);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 422);
        }
    }

    /** Validate preflight scope, exact IDs AND planned occurrence fingerprint. */
    public static function validated_download(int $form): array {
        if (self::authorize() !== $form || isset($_POST['entry_ids']) ||
            isset($_POST['selection_scope']) || isset($_POST['selection_effective'])) {
            throw new RuntimeException('Ambiguous ALL form selection');
        }
        $token = $_POST['all_token'] ?? null;
        $seal = $_POST['all_seal'] ?? null;
        if (!is_string($token) || strlen($token) > 2048 || !is_string($seal) ||
            !hash_equals(self::sign(wp_unslash($token)), $seal)) {
            throw new RuntimeException('ALL preview seal missing or invalid');
        }
        $data = json_decode(wp_unslash($token), true);
        if (!is_array($data) || ($data['v'] ?? null) !== 1 ||
            ($data['form'] ?? null) !== $form ||
            ($data['user'] ?? null) !== get_current_user_id() ||
            !is_int($data['issued'] ?? null) ||
            $data['issued'] > time() + 60 ||
            time() - $data['issued'] > self::TTL) {
            throw new RuntimeException('ALL preview expired or form/user changed');
        }
        $actual = self::manifest($form);
        foreach (['entries', 'files', 'zero_entries', 'ids_hash', 'plan_hash'] as $key) {
            if (!array_key_exists($key, $data) || $data[$key] !== $actual[$key]) {
                throw new RuntimeException('ALL Entries or source files changed; check ALL again');
            }
        }
        if (!$actual['plan']) {
            throw new RuntimeException('ALL has no eligible files; no empty ZIP was sent');
        }
        return [$actual['ids'], $actual['plan'], $actual['zero_entries']];
    }

    /**
     * Last pre-header consistency cutoff: recompute the authoritative eligible
     * occurrence plan, not just active Entry IDs. This intentionally reuses the
     * same GFAPI/deletion-metadata planner and source hashing as Check ALL.
     * A mutation after this check is outside the bounded guarantee.
     */
    public static function assert_manifest(int $form, array $expected_ids, array $expected_plan): void {
        // GF gform_get_meta() keeps an in-request $_gform_lead_meta cache.
        // A separate request can update deletion metadata while this download
        // writes its ZIP. Evict ONLY the two host deletion keys for this form's
        // accepted Entries before reusing the exact same authoritative planner.
        // Do not flush unrelated GF caches or change NEW/Selected behavior.
        global $_gform_lead_meta;
        if (!is_array($_gform_lead_meta)) {
            throw new RuntimeException('Gravity Forms deletion metadata cache unavailable');
        }
        $prefix = get_current_blog_id() . '_';
        foreach ($expected_ids as $entry_id) {
            unset(
                $_gform_lead_meta[$prefix . $entry_id . '_bdfgf_deleted_file_urls'],
                $_gform_lead_meta[$prefix . $entry_id . '_bdfgf_files_deleted']
            );
        }
        $actual = self::manifest($form, false);
        if ($actual['ids'] !== $expected_ids || $actual['plan'] !== $expected_plan) {
            throw new RuntimeException('ALL Entries or eligible source files changed before ZIP delivery; check ALL again');
        }
    }

    public static function footer(): void {
        if (($_GET['page'] ?? '') !== 'gf_entries' ||
            !\GFCommon::current_user_can_any('gravityforms_view_entries')) { return; }
        $form = isset($_GET['id']) && is_scalar($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$form || !is_array(\GFAPI::get_form($form))) { return; }
        $config = wp_json_encode([
            'form' => $form, 'nonce' => wp_create_nonce('gfbrc_independent_flat_zip_' . $form),
            'action' => Flat_Zip_Download::ENDPOINT,
            'text' => [
                'title' => __('ALL — Download all eligible files', 'gf-bulk-rename-counter'),
                'check' => __('Check ALL', 'gf-bulk-rename-counter'),
                'download' => __('Download ALL ZIP', 'gf-bulk-rename-counter'),
                'guide' => __('ALL includes eligible uploads from every active Entry in this form, regardless of filters, selected rows or NEW acknowledgment history. Check before downloading; this does not advance NEW coverage.', 'gf-bulk-rename-counter'),
                'checking' => __('Counting all active Entries and verifying source files…', 'gf-bulk-rename-counter'),
                'failed_check' => __('ALL check failed', 'gf-bulk-rename-counter'),
                'summary' => __('%1$d active Entries / %2$d eligible files / %3$d Entries without files. Entire form; filters, pages, selection and NEW history are ignored.', 'gf-bulk-rename-counter'),
                'empty' => __('No ZIP exists for this scope.', 'gf-bulk-rename-counter'),
                'failed' => __('ALL failed: %s — no partial ZIP delivered.', 'gf-bulk-rename-counter'),
                'confirm' => __('Download ALL %1$d active Entries / %2$d eligible files / %3$d Entries without files? Filters and NEW history are ignored; NEW acknowledgment does not change.', 'gf-bulk-rename-counter'),
            ],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        echo '<script id="gfbrc-all-config" type="application/json">' . $config . '</script>';
        ?>
<style id="gfbrc-all-visibility">
#gfbrc-all-panel [hidden] { display: none !important; }
#gfbrc-all-panel .button {margin:4px;max-width:100%;white-space:normal}
#gfbrc-all-panel .description {margin:8px 0}
</style>
<script id="gfbrc-all-workflow">
(function () {
  'use strict';
  var source = document.getElementById('gfbrc-all-config');
  var apply = document.getElementById('doaction');
  if (!source || !apply) return;
  var cfg = JSON.parse(source.textContent);
  var panel = document.createElement('section');
  panel.id = 'gfbrc-all-panel';
  panel.style.cssText = 'display:block;box-sizing:border-box;margin:12px 0;padding:12px;border:1px solid #c3c4c7;background:#fff;max-width:540px';
  panel.innerHTML = '<strong id="gfbrc-all-title"></strong> ' +
    '<button type="button" class="button" id="gfbrc-all-prepare"></button> ' +
    '<p class="description" id="gfbrc-all-guide"></p>' +
    '<div><span id="gfbrc-all-result" role="status" aria-live="polite"></span></div>' +
    '<div><button type="button" class="button button-primary" id="gfbrc-all-download" hidden></button></div>';
  panel.querySelector('#gfbrc-all-title').textContent = cfg.text.title;
  panel.querySelector('#gfbrc-all-prepare').textContent = cfg.text.check;
  panel.querySelector('#gfbrc-all-guide').textContent = cfg.text.guide;
  panel.querySelector('#gfbrc-all-download').textContent = cfg.text.download;
  function tr(key) {
    var args = Array.prototype.slice.call(arguments, 1), next = 0;
    return cfg.text[key].replace(/%(?:(\d+)\$)?[ds]/g, function (_, index) {
      return String(args[index ? Number(index) - 1 : next++]);
    });
  }
  (apply.closest('.tablenav') || apply).insertAdjacentElement('afterend', panel);
  var prepare = panel.querySelector('#gfbrc-all-prepare');
  var download = panel.querySelector('#gfbrc-all-download');
  var status = panel.querySelector('#gfbrc-all-result');
  var current = null, busy = false;
  function message(s) { status.textContent = s; }
  prepare.addEventListener('click', async function () {
    if (busy) return;
    busy = true; prepare.disabled = true; download.hidden = true; current = null;
    message(cfg.text.checking);
    try {
      var body = new URLSearchParams({action:'gfbrc_all_prepare',
        form_id:String(cfg.form), _wpnonce:cfg.nonce});
      var response = await fetch(new URL('admin-ajax.php', location.href).href, {
        method:'POST', credentials:'same-origin', body:body, headers:{'Cache-Control':'no-store'}});
      var result = await response.json();
      if (!response.ok || !result.success) {
        throw Error((result.data && result.data.message) || cfg.text.failed_check);
      }
      var data = result.data;
      message(tr('summary', data.entries, data.files, data.zero_entries));
      if (data.files > 0) {current = data; download.hidden = false;}
      else message(status.textContent+' '+cfg.text.empty);
    } catch (e) {message(tr('failed', e.message));}
    finally {busy = false; prepare.disabled = false;}
  });
  download.addEventListener('click', function () {
    if (!current || busy) return;
    if (!window.confirm(tr('confirm', current.entries, current.files, current.zero_entries))) return;
    // Browser-submitted IDs, filters, counts cannot establish ALL membership.
    // The signed preview only detects changes; the server re-enumerates it.
    var form = document.createElement('form');
    form.method = 'POST'; form.action = new URL('admin-post.php', location.href).href;
    function hidden(name,value) {
      var el = document.createElement('input');
      el.type = 'hidden'; el.name = name; el.value = value; form.appendChild(el);
    }
    hidden('action', cfg.action);
    hidden('form_id', String(cfg.form));
    hidden('_wpnonce', cfg.nonce);
    hidden('selection_mode','all_form');
    hidden('all_token',current.token);
    hidden('all_seal',current.seal);
    document.body.appendChild(form);
    form.submit();
  });
})();
</script>
        <?php
    }
}
