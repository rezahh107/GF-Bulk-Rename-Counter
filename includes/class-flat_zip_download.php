<?php
/**
 * Independently verified flat ZIP download with GF scoped all-matching selection.
 * @package GFBulkRename
 */
namespace GFBulkRename;
use RuntimeException;
use Throwable;
use ZipArchive;
if (!defined('ABSPATH')) { exit; }

final class Flat_Zip_Download {
    const ACTION = 'gfbrc_independent_flat_zip';
    const ENDPOINT = 'gfbrc_flat_zip_download';

    public static function register(): void {
        add_filter('gform_entry_list_bulk_actions', [__CLASS__, 'add_action'], 20, 2);
        add_action('admin_footer', [__CLASS__, 'footer']);
        add_action('admin_post_' . self::ENDPOINT, [__CLASS__, 'download']);
    }

    public static function add_action($actions, $form_id) {
        if (!is_array($actions) || !\GFCommon::current_user_can_any('gravityforms_view_entries')) { return $actions; }
        $actions[self::ACTION] = __('Download Flat ZIP with Rename', 'gf-bulk-rename-counter');
        return $actions;
    }

    public static function footer(): void {
        if (!isset($_GET['page']) || $_GET['page'] !== 'gf_entries' ||
            !\GFCommon::current_user_can_any('gravityforms_view_entries')) { return; }
        $form_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$form_id) { return; }
        // Seal the actual Entry List query context as rendered for this user.
        // The POST cannot invent a different form/filter/search scope.
        $scope = [];
        $scope_supported = true;
        foreach ($_GET as $key => $value) {
            if (in_array($key, ['page', 'id', 'paged'], true)) { continue; }
            if (!is_string($key) || strlen($key) > 100 || !preg_match('/^[a-zA-Z0-9_-]+$/', $key) ||
                !is_string($value) || strlen($value) > 4096 || count($scope) >= 32) {
                $scope_supported = false;
                break;
            }
            $scope[$key] = wp_unslash($value);
        }
        ksort($scope);
        $scope_json = wp_json_encode($scope);
        if (!is_string($scope_json) || strlen($scope_json) > 8192) { $scope_supported = false; }
        // Show the operator the actual native matching Entry count at page render.
        // The download separately resolves the same criteria and rejects stale counts.
        $matching_count = null;
        if ($scope_supported) {
            try {
                if (!class_exists('\\GF_Entry_List_Table', false)) {
                    require_once \GFCommon::get_base_path() . '/entry_list.php';
                }
                $table = new \GF_Entry_List_Table([
                    'form_id' => $form_id,
                    'filter' => isset($scope['filter']) ? sanitize_text_field($scope['filter']) : '',
                ]);
                $criteria = $table->get_search_criteria();
                if (!is_array($criteria) || !isset($criteria['status'])) {
                    throw new RuntimeException('Native Entry List criteria unavailable');
                }
                $matching_count = \GFAPI::count_entries($form_id, $criteria);
                if (!is_int($matching_count) || $matching_count < 0) {
                    throw new RuntimeException('Native Entry List count unavailable');
                }
            } catch (Throwable $e) {
                $scope_supported = false;
            }
        }
        $config = [
            'scope' => $scope_supported ? $scope_json : '',
            'scopeSeal' => $scope_supported ? self::sign_scope($form_id, $scope_json) : '',
            'matchingCount' => $scope_supported ? $matching_count : null,
            'action' => self::ACTION,
            'postAction' => self::ENDPOINT,
            'formId' => $form_id,
            'nonce' => wp_create_nonce('gfbrc_independent_flat_zip_' . $form_id),
            'required' => __('Select at least one Entry or choose all matching Entries.', 'gf-bulk-rename-counter'),
            'confirm' => __('Download eligible files from %d explicitly checked Entries on this page? File count may differ.', 'gf-bulk-rename-counter'),
            'confirmGlobal' => __('Download eligible files from ALL %d Entries matching this form and its current filters/search, across every page? Individually checked rows do not limit this choice. Maximum 500 Entries per ZIP; the server rechecks the count before download.', 'gf-bulk-rename-counter'),
            'scopeUnavailable' => __('This search/filter URL is not supported for a safe global download.', 'gf-bulk-rename-counter'),
            'globalLabel' => __('All matching Entries (Flat ZIP only; maximum 500)', 'gf-bulk-rename-counter'),
            'noMatches' => __('No Entries match the current form and filters.', 'gf-bulk-rename-counter'),
            'overLimit' => __('More than 500 Entries match. Narrow the current search or filters before downloading.', 'gf-bulk-rename-counter'),
        ];
        echo '<script id="gfbrc-independent-flat-zip" data-config="' .
            esc_attr(wp_json_encode($config)) . '">';
        ?>
(function () {
  'use strict';
  var script = document.getElementById('gfbrc-independent-flat-zip');
  if (!script) return;
  var config = JSON.parse(script.dataset.config);
  function isSelectedAction(button) {
    // Top and bottom selectors are independent controls. Never hijack a
    // native Apply click because the OTHER selector contains our action.
    var name = button.id === 'doaction' ? 'action' : 'action2';
    var select = document.querySelector('select[name="' + name + '"]');
    return !!select && select.value === config.action;
  }
  // Keep the native GF select-all switch authoritative when it is functional.
  // The independent checkbox is an accessible fallback when pinned GF's
  // .column-cb selector does not match its own rendered Entry List header.
  var apply = document.getElementById('doaction');
  if (apply) {
    var label = document.createElement('label');
    label.style.marginInlineStart = '12px';
    var toggle = document.createElement('input');
    toggle.type = 'checkbox';
    toggle.id = 'gfbrc-all-matching';
    toggle.style.marginInlineEnd = '5px';
    label.appendChild(toggle);
    label.appendChild(document.createTextNode(config.globalLabel));
    apply.insertAdjacentElement('afterend', label);
  }
  function intercept(ev) {
    var button = ev.target.closest('input,button');
    if (!button || !/^(doaction|doaction2)$/.test(button.id)) return;
    if (!isSelectedAction(button)) return;
    ev.preventDefault();
    ev.stopImmediatePropagation();
    var checked = document.querySelectorAll(
      'input[type="checkbox"][name="lead[]"]:checked,' +
      'input[type="checkbox"][name="entry[]"]:checked,' +
      'input[type="checkbox"][name="entry_ids[]"]:checked'
    );
    var ids = Array.prototype.map.call(checked, function (x) { return x.value; })
      .filter(function (x) { return /^[1-9][0-9]*$/.test(x); });
    var gfGlobal = document.getElementById('all_entries');
    var fallback = document.getElementById('gfbrc-all-matching');
    var allMatching = (gfGlobal && gfGlobal.value === '1') || (fallback && fallback.checked);
    if (allMatching && (!config.scopeSeal || !Number.isSafeInteger(config.matchingCount))) {
      window.alert(config.scopeUnavailable); return;
    }
    if (allMatching && config.matchingCount === 0) { window.alert(config.noMatches); return; }
    if (allMatching && config.matchingCount > 500) { window.alert(config.overLimit); return; }
    if (!allMatching && !ids.length) { window.alert(config.required); return; }
    if (!window.confirm(allMatching ?
        config.confirmGlobal.replace('%d', String(config.matchingCount)) :
        config.confirm.replace('%d', String(ids.length)))) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = new URL('admin-post.php', window.location.href).href;
    function hidden(name, value) {
      var input = document.createElement('input');
      input.type = 'hidden'; input.name = name; input.value = value; form.appendChild(input);
    }
    hidden('action', config.postAction);
    hidden('form_id', config.formId);
    hidden('_wpnonce', config.nonce);
    hidden('selection_mode', allMatching ? 'all_matching' : 'explicit');
    if (allMatching) {
      hidden('selection_scope', config.scope);
      hidden('selection_scope_seal', config.scopeSeal);
      hidden('expected_entry_count', String(config.matchingCount));
    } else {
      ids.forEach(function (id) { hidden('entry_ids[]', id); });
    }
    document.body.appendChild(form);
    form.submit();
  }
  document.addEventListener('click', intercept, true);
})();
        <?php
        echo '</script>';
    }

    private static function fail(string $message, int $status = 400): void {
        status_header($status);
        wp_die(esc_html($message), esc_html__('Flat ZIP download not available', 'gf-bulk-rename-counter'), ['response' => $status]);
    }

    private static function upload_source(string $url, int $form_id): string {
        // Use GF's configured upload root (including gform_upload_path) and the
        // WP media root. Never resolve other hosts, stream wrappers or traversal.
        $wp = wp_upload_dir();
        $roots = [[(string)$wp['baseurl'], (string)$wp['basedir']]];
        $gf_url = \GFFormsModel::get_upload_url($form_id);
        $gf_path = \GFFormsModel::get_upload_path($form_id);
        if (is_string($gf_url) && is_string($gf_path)) { $roots[] = [$gf_url, $gf_path]; }
        $decoded_url = rawurldecode($url);
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded_url)) { throw new RuntimeException('Invalid stored upload URL'); }
        $u = wp_parse_url($decoded_url);
        if (!is_array($u) || !in_array(strtolower($u['scheme'] ?? ''), ['http','https'], true) ||
            !empty($u['user']) || !empty($u['pass']) || !empty($u['query']) || !empty($u['fragment'])) {
            throw new RuntimeException('Unsupported file URL');
        }
        foreach ($roots as [$baseurl, $basedir]) {
            $base = wp_parse_url($baseurl);
            if (!is_array($base) || strcasecmp($base['host'] ?? '', $u['host'] ?? '') !== 0 ||
                strcasecmp($base['scheme'] ?? '', $u['scheme'] ?? '') !== 0 ||
                ($base['port'] ?? null) !== ($u['port'] ?? null)) { continue; }
            $root_url = rtrim($base['path'] ?? '', '/');
            $path_url = $u['path'] ?? '';
            if (!str_starts_with($path_url, $root_url . '/')) { continue; }
            $relative = substr($path_url, strlen($root_url) + 1);
            $segments = explode('/', $relative);
            if ($relative === '' || in_array('..', $segments, true) || in_array('.', $segments, true) ||
                in_array('', $segments, true)) { throw new RuntimeException('Invalid file path'); }
            $root = realpath($basedir);
            $file = $root ? realpath($root . '/' . $relative) : false;
            if (!$root || !$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) ||
                !is_file($file) || !is_readable($file)) {
                throw new RuntimeException('Referenced upload missing or unreadable');
            }
            return $file;
        }
        throw new RuntimeException('File URL outside configured upload directories');
    }

    /** Fail closed on observable deletion metadata contradictions, not version strings. */
    private static function deleted_urls_for_entry(int $entry_id): array {
        if (!defined('BDFGF_VERSION') || !function_exists('gform_get_meta')) {
            throw new RuntimeException('Bulk Download deletion metadata unavailable');
        }
        $raw = maybe_unserialize(gform_get_meta($entry_id, 'bdfgf_deleted_file_urls'));
        $flag = gform_get_meta($entry_id, 'bdfgf_files_deleted');
        if (in_array($flag, [false, null, '', 0, '0'], true)) {
            $deleted = false;
        } elseif (in_array($flag, [true, 1, '1'], true)) {
            $deleted = true;
        } else {
            throw new RuntimeException('Unrecognized deleted-file flag');
        }
        if (in_array($raw, [false, null, ''], true)) { $raw = []; }
        if (!is_array($raw) || array_values($raw) !== $raw) {
            throw new RuntimeException('Malformed deleted-file URL list');
        }
        $urls = [];
        foreach ($raw as $url) {
            if (!is_string($url) || trim($url) === '' || isset($urls[$url])) {
                throw new RuntimeException('Malformed or duplicate deleted-file URL');
            }
            $urls[$url] = true;
        }
        if ($deleted !== (count($urls) > 0)) {
            throw new RuntimeException('Inconsistent deleted-file flag and URL list');
        }
        return $urls;
    }

    public static function plan(int $form_id, array $ids): array {
        $form = \GFAPI::get_form($form_id);
        if (!is_array($form)) { throw new RuntimeException('Form unavailable'); }
        $fields = [];
        foreach ($form['fields'] as $field) {
            if (($field->type ?? '') === 'fileupload') { $fields[] = (string)$field->id; }
        }
        $opts = get_option('gf_bulk_rename_options', []);
        $counter_field = (int)($opts['counter_field_id'] ?? 0);
        $target_form = (int)($opts['form_id'] ?? 0);
        $rule = Form_Naming_Rules::for_form($form);
        $plan = [];
        foreach ($ids as $entry_id) {
            $entry = \GFAPI::get_entry($entry_id);
            if (!is_array($entry) || (int)($entry['form_id'] ?? 0) !== $form_id) {
                throw new RuntimeException('Selected entry missing or belongs to a different form');
            }
            $deleted_set = self::deleted_urls_for_entry((int)$entry_id);
            $seen_urls = [];
            foreach ($fields as $field_id) {
                $raw = $entry[$field_id] ?? '';
                if (!is_string($raw) || $raw === '') { continue; }
                $decoded = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) { $decoded = [$raw]; }
                if (!is_array($decoded) || array_values($decoded) !== $decoded) {
                    throw new RuntimeException('Invalid stored multi-file data');
                }
                foreach ($decoded as $url) {
                    if (!is_string($url) || trim($url) === '') {
                        throw new RuntimeException('Invalid stored upload reference');
                    }
                    $seen_urls[$url] = true;
                    if (isset($deleted_set[$url])) { continue; }
                    $path = self::upload_source($url, $form_id);
                    $base = sanitize_file_name(wp_basename($path));
                    if ($rule !== null && in_array($rule['method'], ['single', 'composite'], true)) {
                        $base = Naming_Strategy::candidate($rule, $entry, $path);
                    }
                    $counter = $counter_field ? ($entry[(string)$counter_field] ?? null) : null;
                    if (($rule === null || $rule['method'] === 'legacy') &&
                        $counter_field > 0 && (!$target_form || $target_form === $form_id) &&
                        is_scalar($counter) && !empty($counter)) {
                        $value = trim((string)$counter);
                        if ($value !== '') {
                            $ext = pathinfo($path, PATHINFO_EXTENSION);
                            $base = sanitize_file_name('d' . $value . ($ext === '' ? '' : '.' . $ext));
                        }
                    }
                    if ($base === '' || $base === '.' || $base === '..' ||
                        str_contains($base, '/') || str_contains($base, '\\')) {
                        throw new RuntimeException('Unsafe output filename');
                    }
                    $sha = hash_file('sha256', $path);
                    $size = filesize($path);
                    if (!is_string($sha) || $size === false) { throw new RuntimeException('Cannot read source'); }
                    $plan[] = [
                        'entry_id' => $entry_id, 'field_id' => $field_id,
                        'url' => $url, 'source' => $path, 'candidate' => $base,
                        'size' => $size, 'sha256' => $sha,
                    ];
                }
            }
            foreach ($deleted_set as $deleted_url => $_) {
                if (!isset($seen_urls[$deleted_url])) {
                    throw new RuntimeException('Deleted URL not present in source Entry');
                }
            }
        }
        if (!$plan) { throw new RuntimeException('No eligible files found'); }
        // Reserve naturally occurring names before creating Legacy-style suffixes.
        $reserved = [];
        foreach ($plan as $item) { $reserved[strtolower($item['candidate'])] = true; }
        $used = [];
        foreach ($plan as &$item) {
            $name = $item['candidate'];
            if (isset($used[strtolower($name)])) {
                $stem = pathinfo($name, PATHINFO_FILENAME);
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $ext = $ext === '' ? '' : '.' . $ext;
                $found = false;
                for ($n = 0; $n <= 10000; $n++) {
                    $candidate = sanitize_file_name($stem . '-' . $item['entry_id'] .
                        ($n ? '-' . $n : '') . $ext);
                    if ($candidate !== '' && !str_contains($candidate, '/') &&
                        !str_contains($candidate, '\\') &&
                        !isset($reserved[strtolower($candidate)]) &&
                        !isset($used[strtolower($candidate)])) {
                        $name = $candidate;
                        $found = true;
                        break;
                    }
                }
                if (!$found) { throw new RuntimeException('Unique output name unavailable'); }
            }
            $used[strtolower($name)] = true;
            $item['name'] = $name;
        }
        unset($item);
        if (count($used) !== count($plan)) { throw new RuntimeException('Duplicate planned names'); }
        return $plan;
    }

    public static function write_verified_zip(array $plan): string {
        if (!class_exists('ZipArchive')) { throw new RuntimeException('ZIP support unavailable'); }
        $temp = wp_tempnam('gfbrc-independent-flat.zip');
        if (!$temp) { throw new RuntimeException('Temporary ZIP unavailable'); }
        $zip = new ZipArchive();
        try {
            if ($zip->open($temp, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('ZIP open failed');
            }
            try {
                $names = [];
                foreach ($plan as $index => $item) {
                    if (isset($names[strtolower($item['name'])])) {
                        throw new RuntimeException('Duplicate ZIP name');
                    }
                    $names[strtolower($item['name'])] = true;

                    if (!$zip->addFile($item['source'], $item['name'])) {
                        throw new RuntimeException('ZIP addFile failed');
                    }
                }
            } finally {
                $closed = $zip->close();
            }
            if (!$closed) {
                throw new RuntimeException('ZIP finalization failed');
            }
            self::verify_closed_zip($temp, $plan);
            return $temp;
        } catch (Throwable $e) {
            @unlink($temp);
            throw $e;
        }
    }

    /** Verify an already closed ZIP before a response begins (also used in CI mutation controls). */
    public static function verify_closed_zip(string $temp, array $plan): void {
            $read = new ZipArchive();
            if ($read->open($temp) !== true) { throw new RuntimeException('Closed ZIP unreadable'); }
            try {
                if ($read->numFiles !== count($plan)) { throw new RuntimeException('ZIP member count mismatch'); }
                foreach ($plan as $i => $item) {
                    $name = $read->getNameIndex($i);
                    if ($name !== $item['name'] || str_contains($name, '/') || str_contains($name, '\\')) {
                        throw new RuntimeException('ZIP member path mismatch');
                    }
                    $stream = $read->getStream($name);
                    if (!$stream) { throw new RuntimeException('ZIP member unreadable'); }
                    $hash = hash_init('sha256'); $size = 0;
                    while (!feof($stream)) {
                        $chunk = fread($stream, 65536);
                        if ($chunk === false) { fclose($stream); throw new RuntimeException('ZIP streaming read failed'); }
                        $size += strlen($chunk); hash_update($hash, $chunk);
                    }
                    fclose($stream);
                    if ($size !== $item['size'] || hash_final($hash) !== $item['sha256'] ||
                        hash_file('sha256', $item['source']) !== $item['sha256']) {
                        throw new RuntimeException('ZIP member source integrity mismatch');
                    }
                }
            } finally { $read->close(); }
    }


    /**
     * Discard buffers only when PHP permits it, proving a decrease on each
     * iteration. Failing here leaves the verified ZIP temporary file under
     * download()'s existing pre-header exception cleanup.
     */
    private static function clear_output_buffers(): void {
        while (($level = ob_get_level()) > 0) {
            $status = ob_get_status();
            if (!is_array($status) || !isset($status['flags']) ||
                ($status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE) === 0) {
                throw new RuntimeException('Cannot remove output buffer before ZIP response');
            }
            if (!ob_end_clean() || ob_get_level() >= $level) {
                throw new RuntimeException('Output buffer cleanup failed to make progress');
            }
        }
    }

    private static function sign_scope(int $form_id, string $scope_json): string {
        return hash_hmac('sha256', $form_id . '|' . get_current_user_id() . '|' . $scope_json, wp_salt('auth'));
    }

    /** Recompute GF's native filtered selection rather than trusting any posted IDs/count. */
    private static function all_matching_ids(int $form_id): array {
        $scope_json = $_POST['selection_scope'] ?? null;
        $seal = $_POST['selection_scope_seal'] ?? null;
        if (!is_string($scope_json) || strlen($scope_json) > 8192 ||
            !is_string($seal) || !hash_equals(self::sign_scope($form_id, wp_unslash($scope_json)), $seal)) {
            self::fail(__('Invalid or forged Entry List scope', 'gf-bulk-rename-counter'), 403);
        }
        $scope = json_decode(wp_unslash($scope_json), true);
        if (!is_array($scope) || count($scope) > 32) {
            self::fail(__('Invalid Entry List scope', 'gf-bulk-rename-counter'), 403);
        }
        foreach ($scope as $key => $value) {
            if (!is_string($key) || strlen($key) > 100 || !preg_match('/^[a-zA-Z0-9_-]+$/', $key) ||
                in_array($key, ['page', 'id', 'paged'], true) ||
                !is_string($value) || strlen($value) > 4096) {
                self::fail(__('Invalid Entry List filter', 'gf-bulk-rename-counter'), 403);
            }
        }
        if (!is_array(\GFAPI::get_form($form_id))) {
            self::fail(__('Selected form unavailable', 'gf-bulk-rename-counter'), 404);
        }
        // In pinned GF 3.1.1.1, the native list's process_action() obtains its
        // all_entries set with GF_Entry_List_Table::get_search_criteria().
        if (!class_exists('\GF_Entry_List_Table', false)) {
            require_once \GFCommon::get_base_path() . '/entry_list.php';
        }
        if (!class_exists('\GF_Entry_List_Table') ||
            !method_exists('\GF_Entry_List_Table', 'get_search_criteria')) {
            throw new RuntimeException('GF Entry List scope resolver unavailable');
        }
        $original_get = $_GET;
        try {
            $_GET = array_merge(['page' => 'gf_entries', 'id' => (string)$form_id], $scope);
            $table = new \GF_Entry_List_Table([
                'form_id' => $form_id,
                'filter' => isset($scope['filter']) ? sanitize_text_field($scope['filter']) : '',
            ]);
            $criteria = $table->get_search_criteria();
        } finally {
            $_GET = $original_get;
        }
        if (!is_array($criteria) || !isset($criteria['status'])) {
            throw new RuntimeException('GF selection criteria unavailable');
        }

        // Real GF/MariaDB qualification established that plain offset paging
        // can drop/duplicate IDs during concurrent membership changes.
        global $wpdb;
        if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false ||
            $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT') === false) {
            throw new RuntimeException('Consistent Entry selection snapshot unavailable');
        }
        $committed = false;
        try {
            $ids = [];
            $seen = [];
            $total = 0;
            for ($offset = 0; $offset <= 500; $offset += 100) {
                $batch = \GFAPI::get_entry_ids($form_id, $criteria,
                    ['key' => 'id', 'direction' => 'ASC', 'is_numeric' => true],
                    ['offset' => $offset, 'page_size' => 100], $total);
                if (!is_array($batch)) {
                    throw new RuntimeException('GF Entry ID enumeration failed');
                }
                foreach ($batch as $id) {
                    $id = (int)$id;
                    if ($id <= 0 || isset($seen[$id])) {
                        throw new RuntimeException('Non-unique or invalid Entry in selection');
                    }
                    $seen[$id] = true;
                    $ids[] = $id;
                }
                if (count($ids) > 500) {
                    throw new RuntimeException('Selection exceeds 500 Entries; no partial ZIP was generated');
                }
                if (count($batch) < 100) { break; }
            }
            if (count($ids) !== (int)$total || !$ids) {
                throw new RuntimeException('Entry selection incomplete or empty');
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('Entry selection snapshot commit failed');
            }
            $committed = true;
            return $ids;
        } finally {
            if (!$committed) { $wpdb->query('ROLLBACK'); }
        }
    }

    public static function download(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { self::fail(__('POST required', 'gf-bulk-rename-counter'), 405); }
        if (!\GFCommon::current_user_can_any('gravityforms_view_entries')) { self::fail(__('Not authorized', 'gf-bulk-rename-counter'), 403); }
        $form_id = isset($_POST['form_id']) && is_scalar($_POST['form_id']) ? absint($_POST['form_id']) : 0;
        if (!$form_id || !isset($_POST['_wpnonce']) ||
            !is_string($_POST['_wpnonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'gfbrc_independent_flat_zip_' . $form_id)) {
            self::fail(__('Invalid download request', 'gf-bulk-rename-counter'), 403);
        }
        $mode = $_POST['selection_mode'] ?? 'explicit';
        if (!is_string($mode) || !in_array($mode, ['explicit', 'all_matching'], true)) {
            self::fail(__('Invalid selection mode', 'gf-bulk-rename-counter'), 400);
        }
        if ($mode === 'all_matching') {
            if (isset($_POST['entry_ids'])) {
                self::fail(__('Ambiguous Entry selection', 'gf-bulk-rename-counter'), 400);
            }
            try {
                $ids = self::all_matching_ids($form_id);
                $expected = $_POST['expected_entry_count'] ?? null;
                if ($expected !== null && (!is_string($expected) || !ctype_digit($expected) ||
                    (string)(int)$expected !== $expected || (int)$expected !== count($ids))) {
                    throw new RuntimeException('Matching Entry count changed; reload the Entry List and retry');
                }
            } catch (Throwable $e) {
                self::fail(sprintf(__('Cannot resolve all matching Entries: %s', 'gf-bulk-rename-counter'),
                    $e->getMessage()), 422);
            }
        } else {
            $raw = $_POST['entry_ids'] ?? null;
            if (!is_array($raw) || count($raw) < 1 || count($raw) > 500) {
                self::fail(__('Select between 1 and 500 Entries', 'gf-bulk-rename-counter'));
            }
            $ids = [];
            foreach (wp_unslash($raw) as $value) {
                if (!is_string($value) || !ctype_digit($value) || (int)$value <= 0 ||
                    (string)(int)$value !== $value || isset($ids[(int)$value])) {
                    self::fail(__('Invalid or duplicate Entry selection', 'gf-bulk-rename-counter'));
                }
                $ids[(int)$value] = (int)$value;
            }
            $ids = array_values($ids);
        }
        $temp = null;
        try {
            $plan = self::plan($form_id, $ids);
            $temp = self::write_verified_zip($plan);
            $size = filesize($temp);
            if ($size === false || $size <= 0 || headers_sent()) {
                throw new RuntimeException('Validated ZIP response unavailable');
            }
            // Never open the response stream or send ZIP headers until all
            // active output buffers have been safely discarded.
            self::clear_output_buffers();
            if (headers_sent()) {
                throw new RuntimeException('Headers sent during output buffer cleanup');
            }
            // Open before emitting headers; preparation failures must yield an
            // ordinary error page, not a promised but missing attachment.
            $f = fopen($temp, 'rb');
            if (!$f) { throw new RuntimeException('Cannot open ZIP for delivery'); }
            nocache_headers();
            header('Cache-Control: private, no-store, max-age=0');
            header('Content-Type: application/zip');
            header('X-GFBRC-Selected-Entries: ' . count($ids));
            header('X-GFBRC-ZIP-Files: ' . count($plan));
            header('Content-Disposition: attachment; filename="gfbrc-flat-' . $form_id .
                '-' . count($ids) . '-entries-' . count($plan) . '-files.zip"');
            header('Content-Length: ' . $size);
            while (!feof($f)) {
                $bytes = fread($f, 65536);
                if ($bytes === false) { fclose($f); throw new RuntimeException('ZIP response read failed'); }
                echo $bytes;
            }
            fclose($f);
        } catch (Throwable $e) {
            if (!$temp || !headers_sent()) {
                // wp_die() exits the process, so clean up BEFORE invoking it.
                if ($temp && is_file($temp)) { @unlink($temp); }
                self::fail(sprintf(__('Cannot prepare flat ZIP: %s', 'gf-bulk-rename-counter'), $e->getMessage()), 422);
            }
            error_log('GFBRC flat ZIP stream error: ' . $e->getMessage());
        } finally {
            if ($temp && is_file($temp)) { @unlink($temp); }
        }
        exit;
    }
}
