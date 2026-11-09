<?php
/**
 * Bounded, history-independent Recent N selection and one-use preview.
 *
 * @package GFBulkRename
 */
namespace GFBulkRename;

use RuntimeException;
use Throwable;

if (!defined('ABSPATH')) { exit; }

final class Recent_Download {
    private const PREPARE = 'gfbrc_recent_prepare';
    private const COUNTS = ['50', '100', '200', '500'];
    private const TTL = 600;

    public static function register(): void {
        add_action('wp_ajax_' . self::PREPARE, [__CLASS__, 'prepare']);
        add_action('admin_footer', [__CLASS__, 'footer'], 31);
    }

    private static function can_view(int $form): bool {
        return is_user_logged_in() && (current_user_can('gravityforms_view_entries', $form) ||
            current_user_can('gform_full_access'));
    }

    private static function request(string $action, array $extra = []): array {
        $form = $_POST['form_id'] ?? null;
        $n = $_POST['recent_n'] ?? null;
        $nonce = $_POST['_wpnonce'] ?? null;
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $_GET ||
            ($_POST['action'] ?? null) !== $action ||
            array_diff(array_keys($_POST), array_merge(['action', 'form_id', 'recent_n', '_wpnonce'], $extra)) ||
            !is_string($form) || !ctype_digit($form) || (string)(int)$form !== $form || (int)$form < 1 ||
            !in_array($n, self::COUNTS, true) || !is_string($nonce)) {
            throw new RuntimeException(__('Invalid Recent N request', 'gf-bulk-rename-counter'));
        }
        $form = (int)$form;
        if (!self::can_view($form) || wp_get_session_token() === '' ||
            !wp_verify_nonce(wp_unslash($nonce), Flat_Zip_Download::ACTION . '_' . $form)) {
            throw new RuntimeException(__('Invalid download request', 'gf-bulk-rename-counter'));
        }
        return [$form, (int)$n];
    }

    private static function budget(int $entries, int $bytes, float $start, ?float $free): void {
        $memory = wp_convert_hr_to_bytes((string)ini_get('memory_limit'));
        $seconds = (int)ini_get('max_execution_time');
        if (($memory > 0 && $memory - memory_get_usage(true) < 16777216 + $entries * 8192) ||
            ($seconds > 0 && microtime(true) - $start >= max(1, $seconds - 5)) ||
            ($free !== null && $bytes > $free - 16777216)) {
            throw new RuntimeException(__('Recent N exceeds available memory, time or temporary space. Try fewer Entries.', 'gf-bulk-rename-counter'));
        }
    }

    private static function ids(string $table, int $form, int $n): array {
        global $wpdb;
        // GFAPI::get_entry_ids() also counts the entire form in GF 3.1.1.1.
        // The bounded read uses GF's own Entry table and numeric primary key.
        $sql = $wpdb->prepare("SELECT id FROM `{$table}` WHERE form_id = %d AND status = 'active' ORDER BY id DESC LIMIT %d", $form, $n);
        $ids = $wpdb->get_col($sql);
        if ($wpdb->last_error !== '' || $wpdb->last_query !== $sql || count($ids) > $n) {
            throw new RuntimeException(__('Could not verify the complete Recent N selection', 'gf-bulk-rename-counter'));
        }
        $result = [];
        $previous = null;
        foreach ($ids as $id) {
            if ((!is_int($id) && !is_string($id)) || (string)(int)$id !== (string)$id ||
                (int)$id < 1 || ($previous !== null && (int)$id >= $previous)) {
                throw new RuntimeException(__('Could not verify the complete Recent N selection', 'gf-bulk-rename-counter'));
            }
            $previous = (int)$id;
            $result[] = $previous;
        }
        return $result;
    }

    private static function manifest(int $form, int $n, bool $admit_zip = true): array {
        global $wpdb;
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        $free = $admit_zip ? (float)@disk_free_space(get_temp_dir()) : null;
        self::budget($n, 0, $start, $free);
        $table = \GFFormsModel::get_entry_table_name();
        $meta = \GFFormsModel::get_entry_meta_table_name();
        foreach ([$table, $meta, \GFFormsModel::get_form_table_name(), \GFFormsModel::get_meta_table_name()] as $name) {
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $name) ||
                $wpdb->get_var($wpdb->prepare(
                    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $name
                )) !== 'InnoDB') {
                throw new RuntimeException(__('Recent N requires transactional Gravity Forms storage', 'gf-bulk-rename-counter'));
            }
        }
        $connection = $wpdb->get_var('SELECT CONNECTION_ID()');
        if (!$connection || $wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false ||
            $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY') === false) {
            throw new RuntimeException(__('Could not verify the complete Recent N selection', 'gf-bulk-rename-counter'));
        }

        // GF can continue hydrating an Entry after a failed meta query.
        // Check the preceding query before wpdb clears its error on the next one.
        $check_read = static function (string $query) use ($wpdb): string {
            if ($wpdb->last_error !== '') {
                throw new RuntimeException(__('Could not verify the complete Recent N selection', 'gf-bulk-rename-counter'));
            }
            return $query;
        };
        add_filter('query', $check_read, PHP_INT_MIN);
        $committed = false;
        try {
            \GFFormsModel::flush_current_form(\GFFormsModel::get_form_cache_key($form));
            $form_data = \GFAPI::get_form($form);
            if (!is_array($form_data) || (int)($form_data['id'] ?? 0) !== $form || !empty($form_data['is_trash'])) {
                throw new RuntimeException(__('Form unavailable', 'gf-bulk-rename-counter'));
            }
            $ids = self::ids($table, $form, $n);
            Flat_Zip_Download::clear_deletion_meta_cache($ids);
            clearstatcache(true);
            $bytes = 0;
            $admit_source = static function (int $size) use (&$bytes, $ids, $start, $free): void {
                if ($size > PHP_INT_MAX - $bytes) {
                    throw new RuntimeException(__('Recent N exceeds available memory, time or temporary space. Try fewer Entries.', 'gf-bulk-rename-counter'));
                }
                $bytes += $size;
                self::budget(count($ids), $bytes, $start, $free);
            };
            try {
                $plan = $ids ? Flat_Zip_Download::plan($form, $ids, $admit_source) : [];
            } catch (No_Eligible_Files_Exception $e) {
                $plan = [];
            }
            self::budget(count($ids), $bytes, $start, $free);
            if ($wpdb->get_var('SELECT CONNECTION_ID()') !== $connection || $wpdb->query('COMMIT') === false) {
                throw new RuntimeException(__('Could not verify the complete Recent N selection', 'gf-bulk-rename-counter'));
            }
            $committed = true;
        } finally {
            remove_filter('query', $check_read, PHP_INT_MIN);
            if (!$committed) { $wpdb->query('ROLLBACK'); }
        }

        // Detect changes to the limited window while hashing the snapshot's files.
        if (self::ids($table, $form, $n) !== $ids) { throw self::stale(); }
        return [$ids, $plan];
    }

    private static function fingerprint(array $ids, array $plan): string {
        $evidence = wp_json_encode([$ids, $plan], JSON_UNESCAPED_SLASHES);
        if (!is_string($evidence)) {
            throw new RuntimeException(__('Could not verify the complete Recent N selection', 'gf-bulk-rename-counter'));
        }
        return hash('sha256', $evidence);
    }

    private static function sign(string $token): string {
        return hash_hmac('sha256', 'gfbrc-recent-v1|' . get_current_blog_id() . '|' .
            wp_get_session_token() . '|' . $token, wp_salt('auth'));
    }

    private static function stale(): RuntimeException {
        return new RuntimeException(__('Recent N changed, expired or was already used. Check again before downloading.', 'gf-bulk-rename-counter'));
    }

    public static function prepare(): void {
        try {
            [$form, $n] = self::request(self::PREPARE);
            [$ids, $plan] = self::manifest($form, $n);
            $data = [
                'requested' => $n, 'entries' => count($ids), 'files' => count($plan),
                'zero_entries' => count($ids) - count(array_unique(array_column($plan, 'entry_id'))),
            ];
            if ($plan) {
                $token = wp_json_encode([
                    'form' => $form, 'user' => get_current_user_id(), 'n' => $n, 'expires' => time() + self::TTL,
                    'id' => wp_generate_uuid4(), 'fingerprint' => self::fingerprint($ids, $plan),
                ]);
                if (!is_string($token)) { throw self::stale(); }
                $seal = self::sign($token);
                // Store only short-lived replay protection, never Entries, files or history.
                if (!set_transient('gfbrc_recent_' . $seal, 'ready', self::TTL)) { throw self::stale(); }
                $data['token'] = $token;
                $data['seal'] = $seal;
            }
            wp_send_json_success($data);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 422);
        }
    }

    public static function validated_download(): array {
        [$form, $n] = self::request(Flat_Zip_Download::ENDPOINT, ['selection_mode', 'recent_token', 'recent_seal']);
        $token = $_POST['recent_token'] ?? null;
        $seal = $_POST['recent_seal'] ?? null;
        if (!is_string($token) || strlen($token) > 2048 || !is_string($seal) ||
            !hash_equals(self::sign(wp_unslash($token)), $seal)) {
            throw self::stale();
        }
        $data = json_decode(wp_unslash($token), true);
        if (!is_array($data) || $data['form'] !== $form || $data['n'] !== $n ||
            $data['user'] !== get_current_user_id() || time() >= $data['expires'] ||
            get_transient('gfbrc_recent_' . $seal) !== 'ready' ||
            !delete_transient('gfbrc_recent_' . $seal)) {
            throw self::stale();
        }

        [$ids, $plan] = self::manifest($form, $n);
        if (!$plan || !hash_equals($data['fingerprint'], self::fingerprint($ids, $plan))) { throw self::stale(); }
        return [$ids, $plan, $n];
    }

    public static function assert_manifest(int $form, int $n, array $ids, array $plan): void {
        [$fresh_ids, $fresh_plan] = self::manifest($form, $n, false);
        if ($fresh_ids !== $ids || $fresh_plan !== $plan) { throw self::stale(); }
    }

    public static function footer(): void {
        if (($_GET['page'] ?? '') !== 'gf_entries' ||
            !in_array($_GET['view'] ?? '', ['', 'entries'], true)) { return; }
        $form = isset($_GET['id']) && is_scalar($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$form || !self::can_view($form) || !is_array(\GFAPI::get_form($form))) { return; }
        $config = wp_json_encode([
            'form' => $form, 'nonce' => wp_create_nonce(Flat_Zip_Download::ACTION . '_' . $form),
            'prepare' => self::PREPARE, 'download' => Flat_Zip_Download::ENDPOINT,
            'ajaxUrl' => admin_url('admin-ajax.php'), 'downloadUrl' => admin_url('admin-post.php'),
            'checking' => __('Checking Recent N Entries and files…', 'gf-bulk-rename-counter'),
            'summary' => __('Requested: %1$d. Active Entries: %2$d. Eligible files: %3$d. Entries without files: %4$d.', 'gf-bulk-rename-counter'),
            'empty' => __('No ZIP exists for this scope.', 'gf-bulk-rename-counter'),
            'failed' => __('Recent N could not be checked. Check again before downloading.', 'gf-bulk-rename-counter'),
            'sent' => __('Download requested. Check your browser for the ZIP or an error. To download again, check Recent N first.', 'gf-bulk-rename-counter'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>
<template id="gfbrc-recent-template">
  <section id="gfbrc-recent-panel" aria-labelledby="gfbrc-recent-title">
    <div class="gfbrc-panel-header"><h2 id="gfbrc-recent-title"><?php esc_html_e('Recent N — Download files from recent Entries', 'gf-bulk-rename-counter'); ?></h2></div>
    <p class="gfbrc-panel-intro"><?php esc_html_e('Uses the whole form, starting with the highest Entry ID. Search, filters, pages, checked rows and NEW history do not limit this download.', 'gf-bulk-rename-counter'); ?></p>
    <div class="gfbrc-panel-actions">
      <label for="gfbrc-recent-count"><?php esc_html_e('Number of active Entries', 'gf-bulk-rename-counter'); ?></label>
      <select id="gfbrc-recent-count">
        <?php foreach (self::COUNTS as $n) { ?>
          <option value="<?php echo esc_attr($n); ?>"><?php echo esc_html(number_format_i18n((int)$n)); ?></option>
        <?php } ?>
      </select>
      <button type="button" class="button" id="gfbrc-recent-prepare"><?php esc_html_e('Check Recent N', 'gf-bulk-rename-counter'); ?></button>
    </div>
    <p class="gfbrc-panel-result" id="gfbrc-recent-result" role="status" aria-live="polite"></p>
    <div class="gfbrc-panel-actions"><button type="button" class="button button-primary" id="gfbrc-recent-download" hidden><?php esc_html_e('Confirm and download Recent N ZIP', 'gf-bulk-rename-counter'); ?></button></div>
  </section>
</template>
<script id="gfbrc-recent-config" type="application/json"><?php echo $config; ?></script>
<script id="gfbrc-recent-workflow">
(function () {
  'use strict';
  let workspace = document.getElementById('gfbrc-entries-workspace');
  if (!workspace) {
    // An empty native list can omit the Bulk Actions toolbar.
    const guide = document.getElementById('gfbrc-entries-guide');
    if (!guide) return;
    workspace = document.createElement('div');
    workspace.id = 'gfbrc-entries-workspace';
    guide.insertAdjacentElement('beforebegin', workspace);
    workspace.appendChild(guide);
  }
  const cfg = JSON.parse(document.getElementById('gfbrc-recent-config').textContent);
  workspace.appendChild(document.getElementById('gfbrc-recent-template').content.cloneNode(true));
  const count = document.getElementById('gfbrc-recent-count');
  const check = document.getElementById('gfbrc-recent-prepare');
  const download = document.getElementById('gfbrc-recent-download');
  const status = document.getElementById('gfbrc-recent-result');
  const number = new Intl.NumberFormat(document.documentElement.lang || undefined);
  let preview = null;
  function reset(message) {
    preview = null;
    download.hidden = true;
    status.textContent = message;
  }
  count.addEventListener('change', () => reset(''));
  check.addEventListener('click', async () => {
    reset(cfg.checking);
    count.disabled = check.disabled = true;
    try {
      const response = await fetch(cfg.ajaxUrl, {
        method: 'POST', credentials: 'same-origin',
        body: new URLSearchParams({action: cfg.prepare, form_id: cfg.form, recent_n: count.value, _wpnonce: cfg.nonce})
      });
      const result = await response.json();
      const data = result.data;
      if (!response.ok || !result.success || !data || data.requested !== Number(count.value)) {
        reset(data && typeof data.message === 'string' ? data.message : cfg.failed);
        return;
      }
      const counts = [data.requested, data.entries, data.files, data.zero_entries];
      status.textContent = cfg.summary.replace(/%(\d+)\$d/g, (_, index) => number.format(counts[index - 1]));
      if (!data.files) { status.textContent += ' ' + cfg.empty; return; }
      preview = data;
      download.hidden = false;
    } catch (_) {
      reset(cfg.failed);
    } finally {
      count.disabled = check.disabled = false;
    }
  });
  download.addEventListener('click', () => {
    if (!preview) return;
    const post = document.createElement('form');
    post.method = 'POST';
    post.action = cfg.downloadUrl;
    const fields = {action: cfg.download, form_id: cfg.form, _wpnonce: cfg.nonce,
      selection_mode: 'recent_n', recent_n: count.value, recent_token: preview.token, recent_seal: preview.seal};
    for (const [key, value] of Object.entries(fields)) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = key;
      input.value = value;
      post.appendChild(input);
    }
    check.focus();
    reset(cfg.sent);
    document.body.appendChild(post);
    post.submit();
    post.remove();
  });
})();
</script>
        <?php
    }
}
