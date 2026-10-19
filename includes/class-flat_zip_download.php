<?php
/**
 * Independent current-page-only verified flat ZIP download.
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
        $actions[self::ACTION] = __('Download Flat ZIP with Rename (selected on this page only)', 'gf-bulk-rename-counter');
        return $actions;
    }

    public static function footer(): void {
        if (!isset($_GET['page']) || $_GET['page'] !== 'gf_entries' ||
            !\GFCommon::current_user_can_any('gravityforms_view_entries')) { return; }
        $form_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$form_id) { return; }
        $config = [
            'action' => self::ACTION,
            'postAction' => self::ENDPOINT,
            'formId' => $form_id,
            'nonce' => wp_create_nonce('gfbrc_independent_flat_zip_' . $form_id),
            'required' => __('Select at least one entry on this page.', 'gf-bulk-rename-counter'),
            'confirm' => __('Download files from only the %d selected entries on this page? Selections made on other pages are NOT included.', 'gf-bulk-rename-counter'),
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
    if (!ids.length) { window.alert(config.required); return; }
    if (!window.confirm(config.confirm.replace('%d', String(ids.length)))) return;
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
    ids.forEach(function (id) { hidden('entry_ids[]', id); });
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
                    $counter = $counter_field ? ($entry[(string)$counter_field] ?? null) : null;
                    if ($counter_field > 0 && (!$target_form || $target_form === $form_id) &&
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

    public static function download(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { self::fail(__('POST required', 'gf-bulk-rename-counter'), 405); }
        if (!\GFCommon::current_user_can_any('gravityforms_view_entries')) { self::fail(__('Not authorized', 'gf-bulk-rename-counter'), 403); }
        $form_id = isset($_POST['form_id']) && is_scalar($_POST['form_id']) ? absint($_POST['form_id']) : 0;
        if (!$form_id || !isset($_POST['_wpnonce']) ||
            !is_string($_POST['_wpnonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'gfbrc_independent_flat_zip_' . $form_id)) {
            self::fail(__('Invalid download request', 'gf-bulk-rename-counter'), 403);
        }
        $raw = $_POST['entry_ids'] ?? null;
        if (!is_array($raw) || count($raw) < 1 || count($raw) > 500) {
            self::fail(__('Select between 1 and 500 entries', 'gf-bulk-rename-counter'));
        }
        $ids = [];
        foreach (wp_unslash($raw) as $value) {
            if (!is_string($value) || !ctype_digit($value) || (int)$value <= 0 ||
                (string)(int)$value !== $value || isset($ids[(int)$value])) {
                self::fail(__('Invalid or duplicate entry selection', 'gf-bulk-rename-counter'));
            }
            $ids[(int)$value] = (int)$value;
        }
        $temp = null;
        try {
            $plan = self::plan($form_id, array_values($ids));
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
            header('Content-Disposition: attachment; filename="gfbrc-flat-' . $form_id . '.zip"');
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
