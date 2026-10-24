<?php
/**
 * Creation-only incremental Entry downloads. The per-form observed-identity
 * baseline is separate from the exact acknowledged eligible Entry coverage.
 * The temporary operation is not a download ledger or durable ZIP storage.
 */
namespace GFBulkRename;

use RuntimeException;
use Throwable;

if (!defined('ABSPATH')) { exit; }

final class New_Download {
    private const STATE_PREFIX = 'gfbrc_new_coverage_';
    private const OP_PREFIX = 'gfbrc_new_pending_';
    private const TTL = 900;
    private const MAX_ENTRIES = 500;
    private const MAX_HISTORY = 50000;
    private const MAX_STATE_BYTES = 1048576;
    private const MAX_ZIP_BYTES = 134217728; // Browser fetch digest/download envelope: 128 MiB.

    public static function register(): void {
        add_action('admin_footer', [__CLASS__, 'footer']);
        foreach (['prepare', 'download', 'ack'] as $task) {
            add_action('wp_ajax_gfbrc_new_' . $task, [__CLASS__, $task]);
        }
    }

    private static function key(int $form): string {
        return self::STATE_PREFIX . $form;
    }

    private static function pending_key(int $form): string {
        return self::OP_PREFIX . $form;
    }

    private static function check(): int {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
            !\GFCommon::current_user_can_any('gravityforms_view_entries')) {
            throw new RuntimeException('Not authorized');
        }
        $raw = $_POST['form_id'] ?? null;
        if (!is_string($raw) || !ctype_digit($raw) || (string)(int)$raw !== $raw || (int)$raw < 1) {
            throw new RuntimeException('Invalid form');
        }
        $form = (int)$raw;
        $nonce = $_POST['_wpnonce'] ?? null;
        if (!is_string($nonce) || !wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), 'gfbrc_new_' . $form)) {
            throw new RuntimeException('Invalid request nonce');
        }
        if (!is_array(\GFAPI::get_form($form))) {
            throw new RuntimeException('Form unavailable');
        }
        return $form;
    }

    private static function fail(Throwable $e): void {
        wp_send_json_error(['message' => $e->getMessage()], 422);
    }

    private static function raw(int $form): string {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::key($form)
        ));
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('NEW coverage state missing or unreadable');
        }
        return $value;
    }

    private static function ranges(array $ranges): void {
        $last = 0;
        foreach ($ranges as $range) {
            if (!is_array($range) || count($range) !== 2 ||
                !is_int($range[0]) || !is_int($range[1]) ||
                $range[0] <= $last || $range[1] < $range[0]) {
                throw new RuntimeException('Invalid or overlapping coverage ranges');
            }
            $last = $range[1];
        }
    }

    private static function state(string $raw): array {
        if (strlen($raw) > self::MAX_STATE_BYTES) {
            throw new RuntimeException('NEW history exceeds bounded storage');
        }
        $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['schema'] ?? null) !== 1 ||
            !is_int($state['rev'] ?? null) || $state['rev'] < 0 ||
            !is_array($state['known'] ?? null) || !is_array($state['covered'] ?? null)) {
            throw new RuntimeException('Unsupported NEW coverage state');
        }
        self::ranges($state['known']);
        self::ranges($state['covered']);
        return $state;
    }

    private static function seed(int $form): string {
        global $wpdb;
        $default = wp_json_encode(['schema' => 1, 'rev' => 0, 'known' => [], 'covered' => []]);
        // A failed add_option may simply mean another tab won initialization.
        add_option(self::key($form), $default, '', false);
        $raw = self::raw($form);
        self::state($raw);
        return $raw;
    }

    private static function clear_cache(int $form): void {
        wp_cache_delete(self::key($form), 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
    }

    private static function union(array $ranges, array $ids): array {
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) { throw new RuntimeException('Invalid Entry identity'); }
            $ranges[] = [$id, $id];
        }
        usort($ranges, static fn($a, $b) => $a[0] <=> $b[0]);
        $result = [];
        foreach ($ranges as $range) {
            $last = count($result) - 1;
            if ($last >= 0 && $range[0] <= $result[$last][1] + 1) {
                $result[$last][1] = max($result[$last][1], $range[1]);
            } else {
                $result[] = $range;
            }
        }
        return $result;
    }

    private static function known(array $ranges, int $id): bool {
        $low = 0; $high = count($ranges) - 1;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($id < $ranges[$mid][0]) { $high = $mid - 1; }
            elseif ($id > $ranges[$mid][1]) { $low = $mid + 1; }
            else { return true; }
        }
        return false;
    }

    /**
     * One real consistent InnoDB read view for ALL existing IDs (including
     * inactive) and GFAPI's eligible active IDs. A new lower ID committed
     * after this snapshot stays unseen and remains discoverable next time.
     */
    private static function snapshot(int $form, array $state): array {
        global $wpdb;
        if (!class_exists('\GFFormsModel') || !method_exists('\GFFormsModel', 'get_entry_table_name')) {
            throw new RuntimeException('Installed GF Entry storage contract unavailable');
        }
        $table = \GFFormsModel::get_entry_table_name();
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) ||
            $wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false ||
            $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT') === false) {
            throw new RuntimeException('Consistent NEW snapshot unavailable');
        }
        $committed = false;
        try {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, status FROM {$table} WHERE form_id = %d ORDER BY id ASC LIMIT %d",
                $form, self::MAX_HISTORY + 1
            ), ARRAY_A);
            if (!is_array($rows) || count($rows) > self::MAX_HISTORY) {
                throw new RuntimeException('Form has too many historical Entries for safe NEW enumeration');
            }
            $all = []; $sql_active = [];
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                if ($id < 1 || isset($all[$id])) { throw new RuntimeException('Invalid stored Entry identity'); }
                $all[$id] = $id;
                if ($row['status'] === 'active') { $sql_active[$id] = $id; }
            }
            $gf_active = []; $total = 0;
            for ($offset = 0; $offset <= self::MAX_HISTORY; $offset += 200) {
                $part = \GFAPI::get_entry_ids($form, ['status' => 'active'],
                    ['key' => 'id', 'direction' => 'ASC', 'is_numeric' => true],
                    ['offset' => $offset, 'page_size' => 200], $total);
                if (!is_array($part)) { throw new RuntimeException('GFAPI Entry enumeration unavailable'); }
                foreach ($part as $id) {
                    $id = (int)$id;
                    if ($id < 1 || isset($gf_active[$id])) {
                        throw new RuntimeException('Duplicate or invalid GFAPI Entry identity');
                    }
                    $gf_active[$id] = $id;
                }
                if (count($part) < 200) { break; }
            }
            ksort($gf_active, SORT_NUMERIC);
            if (count($gf_active) !== (int)$total ||
                array_values($gf_active) !== array_values($sql_active)) {
                throw new RuntimeException('GFAPI and stored active Entry snapshot differ');
            }
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Snapshot commit failed'); }
            $committed = true;
            $new = [];
            foreach ($gf_active as $id) {
                if (!self::known($state['known'], $id)) { $new[] = $id; }
            }
            if (count($new) > self::MAX_ENTRIES) {
                throw new RuntimeException('NEW exceeds 500 eligible Entries; no partial coverage will be committed');
            }
            return [array_values($all), $new];
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

    /** Preview and final response must agree on every verified occurrence. */
    private static function fingerprint(array $plan): string {
        $items = [];
        foreach ($plan as $item) {
            $items[] = [(int)$item['entry_id'], (string)$item['field_id'],
                (string)$item['url'], (string)$item['name'],
                (int)$item['size'], (string)$item['sha256']];
        }
        return hash('sha256', wp_json_encode($items, JSON_UNESCAPED_SLASHES));
    }

    public static function prepare(): void {
        try {
            $form = self::check();
            $old = self::seed($form);
            $state = self::state($old);
            [$all, $new] = self::snapshot($form, $state);
            // A second check with no NEW Entries is read-only.
            if (!$new && $state['rev'] > 0) {
                wp_send_json_success(['op' => null, 'entries' => 0, 'files' => 0,
                    'zero' => true, 'first' => false, 'expires' => 0]);
            }
            $plan = self::plan($form, $new);
            $op = [
                'v' => 1, 'id' => bin2hex(random_bytes(16)),
                'user' => get_current_user_id(), 'form' => $form,
                'expires' => time() + self::TTL, 'base' => hash('sha256', $old),
                'all' => $all, 'ids' => $new,
                'files' => count($plan), 'fingerprint' => self::fingerprint($plan),
                'zip_sha' => '', 'zero' => count($plan) === 0,
                'status' => 'prepared',
            ];
            if (!set_transient(self::pending_key($form), $op, self::TTL)) {
                throw new RuntimeException('Unable to save pending NEW operation');
            }
            wp_send_json_success([
                'op' => $op['id'], 'entries' => count($new),
                'files' => $op['files'], 'zero' => $op['zero'],
                'first' => $state['rev'] === 0,
                'expires' => self::TTL,
            ]);
        } catch (Throwable $e) { self::fail($e); }
    }

    private static function operation(int $form): array {
        $op = get_transient(self::pending_key($form));
        $id = $_POST['operation'] ?? null;
        if (!is_array($op) || !is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id) ||
            !hash_equals((string)($op['id'] ?? ''), $id) ||
            ($op['v'] ?? null) !== 1 ||
            ($op['user'] ?? null) !== get_current_user_id() ||
            ($op['form'] ?? null) !== $form ||
            !is_int($op['expires'] ?? null) || $op['expires'] < time()) {
            throw new RuntimeException('NEW operation expired or replaced by a newer tab');
        }
        return $op;
    }

    public static function download(): void {
        $tmp = null;
        try {
            $form = self::check();
            $op = self::operation($form);
            if ($op['zero'] || !$op['ids']) {
                throw new RuntimeException('This operation has no files; confirm the zero-file review explicitly');
            }
            if (!in_array($op['status'], ['prepared', 'ready'], true)) {
                throw new RuntimeException('Operation no longer available');
            }
            $plan = self::plan($form, $op['ids']);
            if (count($plan) !== $op['files'] ||
                !hash_equals($op['fingerprint'], self::fingerprint($plan))) {
                throw new RuntimeException('Files changed since NEW selection; prepare a fresh operation');
            }
            $tmp = Flat_Zip_Download::write_verified_zip($plan);
            $bytes = filesize($tmp);
            $sha = hash_file('sha256', $tmp);
            if (!is_int($bytes) || $bytes <= 0 || $bytes > self::MAX_ZIP_BYTES ||
                !is_string($sha) || headers_sent()) {
                throw new RuntimeException('NEW ZIP size or response cannot be validated');
            }
            $handle = fopen($tmp, 'rb');
            if (!$handle) { throw new RuntimeException('NEW ZIP cannot be opened'); }
            $op['zip_sha'] = $sha;
            $op['status'] = 'ready';
            if (!set_transient(self::pending_key($form), $op, max(1, $op['expires'] - time()))) {
                fclose($handle);
                throw new RuntimeException('Unable to bind verified ZIP to pending NEW operation');
            }
            Flat_Zip_Download::prepare_output_buffers_for_new();
            if (headers_sent()) { fclose($handle); throw new RuntimeException('NEW ZIP headers unavailable'); }
            nocache_headers();
            header('Cache-Control: private, no-store, max-age=0');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="gfbrc-new-' . $form . '-' .
                count($op['ids']) . '-entries-' . count($plan) . '-files.zip"');
            header('X-GFBRC-Selected-Entries: ' . count($op['ids']));
            header('X-GFBRC-ZIP-Files: ' . count($plan));
            header('X-GFBRC-ZIP-SHA256: ' . $sha);
            header('Content-Length: ' . $bytes);
            try {
                while (!feof($handle)) {
                    $chunk = fread($handle, 65536);
                    if ($chunk === false) { throw new RuntimeException('NEW ZIP stream failed'); }
                    echo $chunk;
                }
            } finally { fclose($handle); }
            @unlink($tmp);
            exit;
        } catch (Throwable $e) {
            if ($tmp && is_file($tmp)) { @unlink($tmp); }
            if (headers_sent()) {
                error_log('GFBRC NEW response interrupted: ' . $e->getMessage());
                exit;
            }
            self::fail($e);
        }
    }

    public static function ack(): void {
        try {
            $form = self::check();
            $op = self::operation($form);
            if ($op['status'] === 'acknowledged') {
                wp_send_json_success(['result' => 'duplicate', 'covered' => count($op['ids'])]);
            }
            if ($op['status'] !== ($op['zero'] ? 'prepared' : 'ready')) {
                throw new RuntimeException('Download has not completed in the browser');
            }
            $confirmed = $_POST['confirmed'] ?? null;
            if ($confirmed !== 'yes') {
                throw new RuntimeException('Explicit operator confirmation required');
            }
            if (!$op['zero']) {
                $sha = $_POST['sha256'] ?? null;
                if (!is_string($sha) || !hash_equals($op['zip_sha'], $sha)) {
                    throw new RuntimeException('Downloaded ZIP identity differs from verified operation');
                }
            } elseif (($_POST['zero_reviewed'] ?? '') !== 'yes') {
                throw new RuntimeException('File-free Entries require a separate explicit review');
            }
            // Mutation between verified download and explicit confirmation
            // invalidates coverage; this also protects file-free Entries.
            foreach ($op['ids'] as $entry_id) {
                $entry = \GFAPI::get_entry($entry_id);
                if (!is_array($entry) || ($entry['status'] ?? '') !== 'active') {
                    throw new RuntimeException('Selected Entry status changed; retry NEW');
                }
            }
            $now_plan = self::plan($form, $op['ids']);
            if (count($now_plan) !== $op['files'] ||
                !hash_equals($op['fingerprint'], self::fingerprint($now_plan))) {
                throw new RuntimeException('Entry uploads changed before acknowledgment; retry NEW');
            }
            global $wpdb;
            $old = self::raw($form);
            if (!hash_equals($op['base'], hash('sha256', $old))) {
                throw new RuntimeException('NEW coverage changed in another tab; prepare again');
            }
            $state = self::state($old);
            // Record ALL pre-existing identities only as creation-history
            // classification, not as "files received". Confirmed coverage
            // contains ONLY the exact eligible IDs of this operation.
            $next = [
                'schema' => 1, 'rev' => $state['rev'] + 1,
                'known' => self::union($state['known'], $op['all']),
                'covered' => self::union($state['covered'], $op['ids']),
            ];
            $json = wp_json_encode($next);
            if (!is_string($json) || strlen($json) > self::MAX_STATE_BYTES) {
                throw new RuntimeException('Exact NEW coverage exceeded safe storage budget');
            }
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s
                 WHERE option_name = %s AND BINARY option_value = BINARY %s",
                $json, self::key($form), $old
            ));
            self::clear_cache($form);
            if ($affected !== 1) {
                throw new RuntimeException('Concurrent NEW confirmation rejected; refresh before retry');
            }
            if (self::raw($form) !== $json || get_option(self::key($form)) !== $json) {
                throw new RuntimeException('NEW coverage persistence readback failed; inspect before retry');
            }
            $op['status'] = 'acknowledged';
            set_transient(self::pending_key($form), $op, max(1, $op['expires'] - time()));
            wp_send_json_success(['result' => 'acknowledged', 'covered' => count($op['ids'])]);
        } catch (Throwable $e) { self::fail($e); }
    }

    public static function footer(): void {
        if (($_GET['page'] ?? '') !== 'gf_entries' ||
            !\GFCommon::current_user_can_any('gravityforms_view_entries')) { return; }
        $form = isset($_GET['id']) && is_scalar($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$form) { return; }
        $config = wp_json_encode([
            'form' => $form, 'nonce' => wp_create_nonce('gfbrc_new_' . $form),
        ]);
        echo '<script id="gfbrc-new-config" type="application/json">' .
            wp_json_encode(json_decode($config, true), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
        ?>
<script id="gfbrc-new-workflow">
(function () {
  'use strict';
  var source = document.getElementById('gfbrc-new-config');
  var apply = document.getElementById('doaction');
  if (!source || !apply) return;
  var cfg = JSON.parse(source.textContent);
  // Use the actual native Entry List admin path. WP's admin_url can contain
  // mismatched site-directory rewriting in the pinned CI installation.
  cfg.url = new URL('admin-ajax.php', window.location.href).href;
  var panel = document.createElement('section');
  panel.id = 'gfbrc-new-panel';
  panel.style.cssText = 'display:inline-block;vertical-align:middle;margin:10px;padding:10px;border:1px solid #c3c4c7;background:#fff;max-width:540px';
  panel.innerHTML = '<strong>NEW — newly created Entries</strong> ' +
    '<button type="button" class="button" id="gfbrc-new-prepare">Check NEW</button> ' +
    '<span id="gfbrc-new-result" role="status" aria-live="polite"></span>' +
    '<div><button type="button" class="button button-primary" id="gfbrc-new-download" hidden>Download verified ZIP</button> ' +
    '<label id="gfbrc-new-review-wrap" hidden><input type="checkbox" id="gfbrc-new-review"> ' +
    'I saved and checked the ZIP (or reviewed the zero-file Entries)</label> ' +
    '<button type="button" class="button" id="gfbrc-new-ack" hidden>Confirm receipt and advance NEW</button></div>';
  apply.insertAdjacentElement('afterend', panel);
  var prepare = panel.querySelector('#gfbrc-new-prepare'),
      download = panel.querySelector('#gfbrc-new-download'),
      ack = panel.querySelector('#gfbrc-new-ack'),
      message = panel.querySelector('#gfbrc-new-result'),
      reviewWrap = panel.querySelector('#gfbrc-new-review-wrap'),
      review = panel.querySelector('#gfbrc-new-review');
  var operation = null, checksum = '', zero = false, downloaded = false, busy = false;
  function status(s) { message.textContent = s; }
  function reset() {
    operation = null; checksum = ''; zero = false; downloaded = false;
    download.hidden = true; ack.hidden = true; reviewWrap.hidden = true; review.checked = false;
  }
  async function api(action, extra, binary) {
    var body = new URLSearchParams(Object.assign({
      action:'gfbrc_new_'+action, form_id:String(cfg.form), _wpnonce:cfg.nonce
    }, extra || {}));
    var response = await fetch(cfg.url, {method:'POST', credentials:'same-origin', body:body,
      headers:{'Cache-Control':'no-store'}});
    if (binary && response.ok && (response.headers.get('content-type') || '').includes('application/zip')) {
      return {response:response, bytes:await response.arrayBuffer()};
    }
    var payload;
    try { payload = await response.json(); } catch (_) {throw Error('NEW request did not return a valid response');}
    if (!response.ok || !payload.success) {
      throw Error((payload.data && payload.data.message) || 'NEW request rejected');
    }
    return payload.data;
  }
  async function guarded(fn) {
    if (busy) return;
    busy = true; prepare.disabled = true; download.disabled = true; ack.disabled = true;
    try { await fn(); } catch (e) {status('NEW failed: '+e.message+' — coverage unchanged unless confirmation succeeded.');}
    finally {busy = false; prepare.disabled = false; download.disabled = false; ack.disabled = false;}
  }
  prepare.addEventListener('click', function () {
    guarded(async function () {
      reset(); status('Checking exact Entries and files…');
      var data = await api('prepare');
      operation = data.op; zero = data.zero;
      status(data.entries+' Entries / '+data.files+' eligible files. '+
        (data.first?'Initial batch; no automatic coverage update. ':'')+
        (zero?'No ZIP exists for this batch; review the file-free Entries.':''));
      if (!data.op) {
        status('0 newly created eligible Entries / 0 files. Nothing to acknowledge.');
      } else if (!zero) download.hidden = false;
      else {downloaded = true; reviewWrap.hidden = false; ack.hidden = false;}
    });
  });
  download.addEventListener('click', function () {
    guarded(async function () {
      if (!operation || zero) return;
      status('Receiving and verifying the ZIP in this browser…');
      var delivered = await api('download', {operation:operation}, true);
      var expected = delivered.response.headers.get('X-GFBRC-ZIP-SHA256');
      var digest = await crypto.subtle.digest('SHA-256', delivered.bytes);
      var actual = Array.from(new Uint8Array(digest)).map(x=>x.toString(16).padStart(2,'0')).join('');
      if (!expected || expected !== actual) throw Error('Downloaded ZIP SHA-256 mismatch');
      checksum = actual;
      var name = (delivered.response.headers.get('content-disposition') || '')
        .match(/filename="([^"]+\.zip)"/i);
      var blob = new Blob([delivered.bytes], {type:'application/zip'});
      var url = URL.createObjectURL(blob);
      var anchor = document.createElement('a'); anchor.href = url;
      anchor.download = name ? name[1] : 'gfbrc-new.zip';
      document.body.appendChild(anchor); anchor.click(); anchor.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
      downloaded = true; reviewWrap.hidden = false; ack.hidden = false;
      status('ZIP received and SHA-256 matched. Check the saved file, then explicitly confirm.');
    });
  });
  ack.addEventListener('click', function () {
    guarded(async function () {
      if (!operation || !downloaded || !review.checked) {
        status('Please check the saved ZIP or explicitly review the zero-file Entries first.'); return;
      }
      var result = await api('ack', {
        operation:operation, confirmed:'yes', zero_reviewed:zero?'yes':'no', sha256:checksum
      });
      status('NEW '+result.result+': '+result.covered+' Entries confirmed. Check NEW again for later Entries.');
      reset();
    });
  });
})();
</script>
        <?php
    }
}
