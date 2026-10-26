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
    private const CAPTURE_TTL = 600;
    private static $native_initial = null;
    private static $native_effective = null;
    private static $initial_calls = 0;
    private static $effective_calls = 0;

    public static function register(): void {
        add_filter('gform_entry_list_bulk_actions', [__CLASS__, 'add_action'], 20, 2);
        add_action('admin_init', [__CLASS__, 'register_capture'], 1);
        add_action('admin_footer', [__CLASS__, 'footer']);
        add_action('admin_post_' . self::ENDPOINT, [__CLASS__, 'download']);
        New_Download::register();
        All_Download::register();
    }


    /**
     * Observe the actual native Entry List query, not a second guessed query.
     * GF's gf_apply_filters executes the generic hook followed by the
     * form-specific hook. Guard observer ordering after prepare_items().
     */
    public static function register_capture(): void {
        if (($_GET['page'] ?? null) !== 'gf_entries' ||
            !\GFCommon::current_user_can_any('gravityforms_view_entries')) { return; }
        $form_id = isset($_GET['id']) && is_scalar($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$form_id) { return; }
        add_filter('gform_get_entries_args_entry_list', [__CLASS__, 'capture_initial'], PHP_INT_MIN, 1);
        add_filter('gform_get_entries_args_entry_list_' . $form_id, [__CLASS__, 'capture_effective'], PHP_INT_MAX, 1);
    }

    public static function capture_initial($args) {
        self::$initial_calls++;
        self::$native_initial = $args;
        return $args;
    }

    public static function capture_effective($args) {
        self::$effective_calls++;
        self::$native_effective = $args;
        return $args;
    }

    private static function hook_boundary(int $form_id): bool {
        global $wp_filter;
        $positions = [
            ['gform_get_entries_args_entry_list', 'capture_initial', PHP_INT_MIN, true],
            ['gform_get_entries_args_entry_list_' . $form_id, 'capture_effective', PHP_INT_MAX, false],
        ];
        foreach ($positions as [$name, $method, $priority, $first]) {
            $hook = $wp_filter[$name] ?? null;
            if (!($hook instanceof \WP_Hook) || !is_array($hook->callbacks) || !$hook->callbacks) {
                return false;
            }
            $priorities = array_keys($hook->callbacks);
            $at = $first ? reset($priorities) : end($priorities);
            if ($at !== $priority) { return false; }
            $callbacks = $hook->callbacks[$priority];
            $entry = $first ? reset($callbacks) : end($callbacks);
            if (!is_array($entry) || ($entry['function'] ?? null) !== [__CLASS__, $method]) {
                // A callback registered before the initial observer or after
                // the final observer invalidates our observation boundary.
                return false;
            }
        }
        return self::$initial_calls === 1 && self::$effective_calls === 1;
    }

    /** Conservative supported subset of GFAPI search criteria, without coercion. */
    private static function supported_criteria($criteria): bool {
        if (!is_array($criteria) || !isset($criteria['status']) ||
            !in_array($criteria['status'], ['active', 'spam', 'trash'], true) ||
            array_diff(array_keys($criteria), ['status', 'field_filters', 'start_date', 'end_date'])) {
            return false;
        }
        foreach (['start_date', 'end_date'] as $date_key) {
            if (isset($criteria[$date_key]) && !is_string($criteria[$date_key])) { return false; }
        }
        if (!isset($criteria['field_filters'])) { return true; }
        $filters = $criteria['field_filters'];
        if (!is_array($filters) || count($filters) > 50) { return false; }
        foreach ($filters as $index => $filter) {
            if ($index === 'mode') {
                if (!in_array($filter, ['all', 'any'], true)) { return false; }
                continue;
            }
            if (!is_int($index) || !is_array($filter) ||
                array_diff(array_keys($filter), ['key', 'operator', 'value']) ||
                !isset($filter['key']) || !is_scalar($filter['key']) ||
                !array_key_exists('value', $filter)) { return false; }
            if (isset($filter['operator']) && !is_string($filter['operator'])) { return false; }
            $value = $filter['value'];
            if (is_array($value)) {
                if (count($value) > 50 || array_filter($value, static function ($v) {
                    return !is_scalar($v) && $v !== null;
                })) { return false; }
            } elseif (!is_scalar($value) && $value !== null) {
                return false;
            }
        }
        return true;
    }

    private static function supported_args($args, int $form_id): bool {
        return is_array($args) && count($args) === 4 &&
            isset($args['form_id'], $args['search_criteria'], $args['sorting'], $args['paging']) &&
            (int)$args['form_id'] === $form_id &&
            is_array($args['sorting']) && is_array($args['paging']) &&
            self::supported_criteria($args['search_criteria']) &&
            is_string(wp_json_encode($args)) && strlen(wp_json_encode($args)) <= 8192;
    }

    /** Sort only associative keys. Preserve positional filter order/mode. */
    private static function canonical($value) {
        if (!is_array($value)) { return $value; }
        if (!array_is_list($value)) { ksort($value); }
        foreach ($value as $key => $part) { $value[$key] = self::canonical($part); }
        return $value;
    }

    private static function sign_effective(string $payload): string {
        return hash_hmac('sha256', 'gfbrc-effective-v1|' . $payload, wp_salt('auth'));
    }

    /** Only genuine complete native render output can authorize ALL. */
    private static function effective_for_display(int $form_id, string $scope_json): array {
        if (!self::hook_boundary($form_id) ||
            !self::supported_args(self::$native_initial, $form_id) ||
            !self::supported_args(self::$native_effective, $form_id) ||
            self::canonical(self::$native_initial['sorting']) !== self::canonical(self::$native_effective['sorting']) ||
            self::canonical(self::$native_initial['paging']) !== self::canonical(self::$native_effective['paging'])) {
            throw new RuntimeException(__('Native Entry List effective filter boundary is unsupported', 'gf-bulk-rename-counter'));
        }
        $payload = [
            'v' => 1, 'form_id' => $form_id, 'user_id' => get_current_user_id(),
            'scope' => $scope_json, 'issued_at' => time(),
            'criteria' => self::$native_effective['search_criteria'],
            'sorting' => self::$native_initial['sorting'],
            'paging' => self::$native_initial['paging'],
        ];
        $json = wp_json_encode($payload);
        if (!is_string($json) || strlen($json) > 8192) {
            throw new RuntimeException(__('Native Entry List capture exceeds safe size', 'gf-bulk-rename-counter'));
        }
        return [$json, self::sign_effective($json), $payload['criteria']];
    }

    /** Current documented callbacks revalidate display criteria; no ID/number shortcuts. */
    private static function revalidated_criteria(int $form_id, array $scope, string $scope_json): array {
        $raw = $_POST['selection_effective'] ?? null;
        $seal = $_POST['selection_effective_seal'] ?? null;
        if (!is_string($raw) || strlen($raw) > 8192 || !is_string($seal) ||
            !hash_equals(self::sign_effective(wp_unslash($raw)), $seal)) {
            throw new RuntimeException(__('Missing or invalid signed native Entry List criteria', 'gf-bulk-rename-counter'));
        }
        $payload = json_decode(wp_unslash($raw), true);
        if (!is_array($payload) || ($payload['v'] ?? null) !== 1 ||
            ($payload['form_id'] ?? null) !== $form_id ||
            ($payload['user_id'] ?? null) !== get_current_user_id() ||
            ($payload['scope'] ?? null) !== $scope_json ||
            !is_int($payload['issued_at'] ?? null) ||
            $payload['issued_at'] > time() + 60 ||
            time() - $payload['issued_at'] > self::CAPTURE_TTL ||
            !is_array($payload['sorting'] ?? null) || !is_array($payload['paging'] ?? null) ||
            !self::supported_criteria($payload['criteria'] ?? null)) {
            throw new RuntimeException(__('Entry List authorization context changed or expired', 'gf-bulk-rename-counter'));
        }
        if (!class_exists('\GF_Entry_List_Table', false)) {
            require_once \GFCommon::get_base_path() . '/entry_list.php';
        }
        if (!class_exists('\GF_Entry_List_Table') ||
            !method_exists('\GF_Entry_List_Table', 'get_search_criteria') ||
            !function_exists('gf_apply_filters')) {
            throw new RuntimeException(__('Native Gravity Forms query resolver unavailable', 'gf-bulk-rename-counter'));
        }
        $old_get = $_GET;
        try {
            $_GET = array_merge(['page' => 'gf_entries', 'id' => (string)$form_id], $scope);
            $table = new \GF_Entry_List_Table([
                'form_id' => $form_id,
                'filter' => isset($scope['filter']) ? sanitize_text_field($scope['filter']) : '',
            ]);
            $base = $table->get_search_criteria();
            if (!self::supported_criteria($base)) {
                throw new RuntimeException(__('Native Entry List base filter shape unavailable', 'gf-bulk-rename-counter'));
            }
            $current = gf_apply_filters(['gform_get_entries_args_entry_list', $form_id], [
                'form_id' => $form_id, 'search_criteria' => $base,
                'sorting' => $payload['sorting'], 'paging' => $payload['paging'],
            ]);
        } finally {
            $_GET = $old_get;
        }
        if (!self::supported_args($current, $form_id) ||
            self::canonical($current['sorting']) !== self::canonical($payload['sorting']) ||
            self::canonical($current['paging']) !== self::canonical($payload['paging']) ||
            self::canonical($current['search_criteria']) !== self::canonical($payload['criteria'])) {
            throw new RuntimeException(__('Effective Entry List filter criteria changed; reload the list', 'gf-bulk-rename-counter'));
        }
        return $current['search_criteria'];
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
        $effective_json = '';
        $effective_seal = '';
        if ($scope_supported) {
            try {
                [$effective_json, $effective_seal, $criteria] =
                    self::effective_for_display($form_id, $scope_json);
                $matching_count = \GFAPI::count_entries($form_id, $criteria);
                if (!is_int($matching_count) || $matching_count < 0) {
                    throw new RuntimeException(__('Native Entry List count unavailable', 'gf-bulk-rename-counter'));
                }
            } catch (Throwable $e) {
                $scope_supported = false;
            }
        }
        $config = [
            'scope' => $scope_supported ? $scope_json : '',
            'scopeSeal' => $scope_supported ? self::sign_scope($form_id, $scope_json) : '',
            'matchingCount' => $scope_supported ? $matching_count : null,
            'effectiveCapture' => $scope_supported ? $effective_json : '',
            'effectiveSeal' => $scope_supported ? $effective_seal : '',
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
    if (allMatching && (!config.scopeSeal || !config.effectiveSeal || !config.effectiveCapture || !Number.isSafeInteger(config.matchingCount))) {
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
      hidden('selection_effective', config.effectiveCapture);
      hidden('selection_effective_seal', config.effectiveSeal);
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
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded_url)) { throw new RuntimeException(__('Invalid stored upload URL', 'gf-bulk-rename-counter')); }
        $u = wp_parse_url($decoded_url);
        if (!is_array($u) || !in_array(strtolower($u['scheme'] ?? ''), ['http','https'], true) ||
            !empty($u['user']) || !empty($u['pass']) || !empty($u['query']) || !empty($u['fragment'])) {
            throw new RuntimeException(__('Unsupported file URL', 'gf-bulk-rename-counter'));
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
                in_array('', $segments, true)) { throw new RuntimeException(__('Invalid file path', 'gf-bulk-rename-counter')); }
            $root = realpath($basedir);
            $file = $root ? realpath($root . '/' . $relative) : false;
            if (!$root || !$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) ||
                !is_file($file) || !is_readable($file)) {
                throw new RuntimeException(__('Referenced upload missing or unreadable', 'gf-bulk-rename-counter'));
            }
            return $file;
        }
        throw new RuntimeException(__('File URL outside configured upload directories', 'gf-bulk-rename-counter'));
    }

    /** Fail closed on observable deletion metadata contradictions, not version strings. */
    private static function deleted_urls_for_entry(int $entry_id): array {
        if (!defined('BDFGF_VERSION') || !function_exists('gform_get_meta')) {
            throw new RuntimeException(__('Bulk Download deletion metadata unavailable', 'gf-bulk-rename-counter'));
        }
        $raw = maybe_unserialize(gform_get_meta($entry_id, 'bdfgf_deleted_file_urls'));
        $flag = gform_get_meta($entry_id, 'bdfgf_files_deleted');
        if (in_array($flag, [false, null, '', 0, '0'], true)) {
            $deleted = false;
        } elseif (in_array($flag, [true, 1, '1'], true)) {
            $deleted = true;
        } else {
            throw new RuntimeException(__('Unrecognized deleted-file flag', 'gf-bulk-rename-counter'));
        }
        if (in_array($raw, [false, null, ''], true)) { $raw = []; }
        if (!is_array($raw) || array_values($raw) !== $raw) {
            throw new RuntimeException(__('Malformed deleted-file URL list', 'gf-bulk-rename-counter'));
        }
        $urls = [];
        foreach ($raw as $url) {
            if (!is_string($url) || trim($url) === '' || isset($urls[$url])) {
                throw new RuntimeException(__('Malformed or duplicate deleted-file URL', 'gf-bulk-rename-counter'));
            }
            $urls[$url] = true;
        }
        if ($deleted !== (count($urls) > 0)) {
            throw new RuntimeException(__('Inconsistent deleted-file flag and URL list', 'gf-bulk-rename-counter'));
        }
        return $urls;
    }

    public static function plan(int $form_id, array $ids): array {
        $form = \GFAPI::get_form($form_id);
        if (!is_array($form)) { throw new RuntimeException(__('Form unavailable', 'gf-bulk-rename-counter')); }
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
                throw new RuntimeException(__('Selected entry missing or belongs to a different form', 'gf-bulk-rename-counter'));
            }
            $deleted_set = self::deleted_urls_for_entry((int)$entry_id);
            $seen_urls = [];
            foreach ($fields as $field_id) {
                $raw = $entry[$field_id] ?? '';
                if (!is_string($raw) || $raw === '') { continue; }
                $decoded = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) { $decoded = [$raw]; }
                if (!is_array($decoded) || array_values($decoded) !== $decoded) {
                    throw new RuntimeException(__('Invalid stored multi-file data', 'gf-bulk-rename-counter'));
                }
                foreach ($decoded as $url) {
                    if (!is_string($url) || trim($url) === '') {
                        throw new RuntimeException(__('Invalid stored upload reference', 'gf-bulk-rename-counter'));
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
                        throw new RuntimeException(__('Unsafe output filename', 'gf-bulk-rename-counter'));
                    }
                    $sha = hash_file('sha256', $path);
                    $size = filesize($path);
                    if (!is_string($sha) || $size === false) { throw new RuntimeException(__('Cannot read source', 'gf-bulk-rename-counter')); }
                    $plan[] = [
                        'entry_id' => $entry_id, 'field_id' => $field_id,
                        'url' => $url, 'source' => $path, 'candidate' => $base,
                        'size' => $size, 'sha256' => $sha,
                    ];
                }
            }
            foreach ($deleted_set as $deleted_url => $_) {
                if (!isset($seen_urls[$deleted_url])) {
                    throw new RuntimeException(__('Deleted URL not present in source Entry', 'gf-bulk-rename-counter'));
                }
            }
        }
        if (!$plan) { throw new RuntimeException(__('No eligible files found', 'gf-bulk-rename-counter')); }
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
                if (!$found) { throw new RuntimeException(__('Unique output name unavailable', 'gf-bulk-rename-counter')); }
            }
            $used[strtolower($name)] = true;
            $item['name'] = $name;
        }
        unset($item);
        if (count($used) !== count($plan)) { throw new RuntimeException(__('Duplicate planned names', 'gf-bulk-rename-counter')); }
        return $plan;
    }

    public static function write_verified_zip(array $plan): string {
        if (!class_exists('ZipArchive')) { throw new RuntimeException(__('ZIP support unavailable', 'gf-bulk-rename-counter')); }
        $temp = wp_tempnam('gfbrc-independent-flat.zip');
        if (!$temp) { throw new RuntimeException(__('Temporary ZIP unavailable', 'gf-bulk-rename-counter')); }
        $zip = new ZipArchive();
        try {
            if ($zip->open($temp, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException(__('ZIP open failed', 'gf-bulk-rename-counter'));
            }
            try {
                $names = [];
                foreach ($plan as $index => $item) {
                    if (isset($names[strtolower($item['name'])])) {
                        throw new RuntimeException(__('Duplicate ZIP name', 'gf-bulk-rename-counter'));
                    }
                    $names[strtolower($item['name'])] = true;

                    if (!$zip->addFile($item['source'], $item['name'])) {
                        throw new RuntimeException(__('ZIP addFile failed', 'gf-bulk-rename-counter'));
                    }
                }
            } finally {
                $closed = $zip->close();
            }
            if (!$closed) {
                throw new RuntimeException(__('ZIP finalization failed', 'gf-bulk-rename-counter'));
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
            if ($read->open($temp) !== true) { throw new RuntimeException(__('Closed ZIP unreadable', 'gf-bulk-rename-counter')); }
            try {
                if ($read->numFiles !== count($plan)) { throw new RuntimeException(__('ZIP member count mismatch', 'gf-bulk-rename-counter')); }
                foreach ($plan as $i => $item) {
                    $name = $read->getNameIndex($i);
                    if ($name !== $item['name'] || str_contains($name, '/') || str_contains($name, '\\')) {
                        throw new RuntimeException(__('ZIP member path mismatch', 'gf-bulk-rename-counter'));
                    }
                    $stream = $read->getStream($name);
                    if (!$stream) { throw new RuntimeException(__('ZIP member unreadable', 'gf-bulk-rename-counter')); }
                    $hash = hash_init('sha256'); $size = 0;
                    while (!feof($stream)) {
                        $chunk = fread($stream, 65536);
                        if ($chunk === false) { fclose($stream); throw new RuntimeException(__('ZIP streaming read failed', 'gf-bulk-rename-counter')); }
                        $size += strlen($chunk); hash_update($hash, $chunk);
                    }
                    fclose($stream);
                    if ($size !== $item['size'] || hash_final($hash) !== $item['sha256'] ||
                        hash_file('sha256', $item['source']) !== $item['sha256']) {
                        throw new RuntimeException(__('ZIP member source integrity mismatch', 'gf-bulk-rename-counter'));
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
                throw new RuntimeException(__('Cannot remove output buffer before ZIP response', 'gf-bulk-rename-counter'));
            }
            if (!ob_end_clean() || ob_get_level() >= $level) {
                throw new RuntimeException(__('Output buffer cleanup failed to make progress', 'gf-bulk-rename-counter'));
            }
        }
    }

    /** Share the verified pre-header guard with the separate NEW response. */
    public static function prepare_output_buffers_for_new(): void {
        self::clear_output_buffers();
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
        // The signed final display criteria, not the unfiltered base query,
        // own membership. Replaying the documented callbacks must agree.
        $criteria = self::revalidated_criteria($form_id, $scope, wp_unslash($scope_json));

        // Real GF/MariaDB qualification established that plain offset paging
        // can drop/duplicate IDs during concurrent membership changes.
        global $wpdb;
        if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false ||
            $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT') === false) {
            throw new RuntimeException(__('Consistent Entry selection snapshot unavailable', 'gf-bulk-rename-counter'));
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
                    throw new RuntimeException(__('GF Entry ID enumeration failed', 'gf-bulk-rename-counter'));
                }
                foreach ($batch as $id) {
                    $id = (int)$id;
                    if ($id <= 0 || isset($seen[$id])) {
                        throw new RuntimeException(__('Non-unique or invalid Entry in selection', 'gf-bulk-rename-counter'));
                    }
                    $seen[$id] = true;
                    $ids[] = $id;
                }
                if (count($ids) > 500) {
                    throw new RuntimeException(__('Selection exceeds 500 Entries; no partial ZIP was generated', 'gf-bulk-rename-counter'));
                }
                if (count($batch) < 100) { break; }
            }
            if (count($ids) !== (int)$total || !$ids) {
                throw new RuntimeException(__('Entry selection incomplete or empty', 'gf-bulk-rename-counter'));
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException(__('Entry selection snapshot commit failed', 'gf-bulk-rename-counter'));
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
        if (!is_string($mode) || !in_array($mode, ['explicit', 'all_matching', 'all_form'], true)) {
            self::fail(__('Invalid selection mode', 'gf-bulk-rename-counter'), 400);
        }
        $all_plan = null;
        $all_zero = null;
        if ($mode === 'all_form') {
            try {
                [$ids, $all_plan, $all_zero] = All_Download::validated_download($form_id);
            } catch (Throwable $e) {
                self::fail(sprintf(__('Cannot resolve ALL: %s', 'gf-bulk-rename-counter'),
                    $e->getMessage()), 422);
            }
        } elseif ($mode === 'all_matching') {
            if (isset($_POST['entry_ids'])) {
                self::fail(__('Ambiguous Entry selection', 'gf-bulk-rename-counter'), 400);
            }
            try {
                $ids = self::all_matching_ids($form_id);
                $expected = $_POST['expected_entry_count'] ?? null;
                if ($expected !== null && (!is_string($expected) || !ctype_digit($expected) ||
                    (string)(int)$expected !== $expected || (int)$expected !== count($ids))) {
                    throw new RuntimeException(__('Matching Entry count changed; reload the Entry List and retry', 'gf-bulk-rename-counter'));
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
            $plan = $all_plan ?? self::plan($form_id, $ids);
            $temp = self::write_verified_zip($plan);
            if ($mode === 'all_form') {
                All_Download::assert_manifest($form_id, $ids, $plan);
            }
            $size = filesize($temp);
            if ($size === false || $size <= 0 || headers_sent()) {
                throw new RuntimeException(__('Validated ZIP response unavailable', 'gf-bulk-rename-counter'));
            }
            // Never open the response stream or send ZIP headers until all
            // active output buffers have been safely discarded.
            self::clear_output_buffers();
            if (headers_sent()) {
                throw new RuntimeException(__('Headers sent during output buffer cleanup', 'gf-bulk-rename-counter'));
            }
            // Open before emitting headers; preparation failures must yield an
            // ordinary error page, not a promised but missing attachment.
            $f = fopen($temp, 'rb');
            if (!$f) { throw new RuntimeException(__('Cannot open ZIP for delivery', 'gf-bulk-rename-counter')); }
            nocache_headers();
            header('Cache-Control: private, no-store, max-age=0');
            header('Content-Type: application/zip');
            header('X-GFBRC-Selected-Entries: ' . count($ids));
            header('X-GFBRC-ZIP-Files: ' . count($plan));
            if ($mode === 'all_form') {
                header('X-GFBRC-ALL: 1');
                header('X-GFBRC-Entries-Without-Files: ' . $all_zero);
            }
            $prefix = $mode === 'all_form' ? 'gfbrc-all-' : 'gfbrc-flat-';
            header('Content-Disposition: attachment; filename="' . $prefix . $form_id .
                '-' . count($ids) . '-entries-' . count($plan) . '-files.zip"');
            header('Content-Length: ' . $size);
            while (!feof($f)) {
                $bytes = fread($f, 65536);
                if ($bytes === false) { fclose($f); throw new RuntimeException(__('ZIP response read failed', 'gf-bulk-rename-counter')); }
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
