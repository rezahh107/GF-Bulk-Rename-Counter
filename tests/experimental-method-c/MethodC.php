<?php
/**
 * EXPERIMENT ONLY — copied into the disposable CI Bulk Download 4.2.0 tree.
 * Never load this from GFBRC's production package.
 */
namespace BDFGF\Actions;

use ZipArchive;

final class MethodC {
    /** @var array<int,array<string,mixed>> */
    private static array $plan = [];

    public static function write(array $uploaded_files, ZipArchive $zip, array $form, callable $sanitize): ZipArchive {
        self::$plan = [];
        foreach ($uploaded_files as $entry_id => $files) {
            foreach (array_values($files) as $ordinal => $path) {
                if (!is_string($path) || !is_file($path) || !is_readable($path)) {
                    throw new \RuntimeException('METHOD_C_SOURCE_UNAVAILABLE');
                }
                $digest = hash_file('sha256', $path);
                if ($digest === false) {
                    throw new \RuntimeException('METHOD_C_SOURCE_HASH_FAILED');
                }
                $base = sanitize_file_name(wp_basename($path));
                $fallback = (int)$entry_id . '/' . $base;
                $candidate = $fallback;
                if (($form['bulkDownloadSettings']['customFoldername'] ?? null) === true) {
                    $entry = \GFAPI::get_entry((int)$entry_id);
                    $folder = \GFCommon::replace_variables(
                        $form['bulkDownloadSettings']['downloadFoldername'], $form, $entry
                    );
                    if ($folder !== '' && strpos($folder, '{') === false) {
                        $folder = sanitize_file_name(wp_basename($folder));
                        if ($folder !== '') {
                            $candidate = $folder . '/' . $base;
                        }
                    }
                }
                $candidate = gf_apply_filters(
                    ['bdfgf_entry_filename', $form['id']], $candidate, (int)$entry_id, $path
                );
                // Use the *original host's private final sanitizer*, then enforce root-only paths.
                $candidate = $sanitize($candidate, $fallback);
                $candidate = sanitize_file_name(wp_basename($candidate));
                if ($candidate === '' || $candidate === '.' || $candidate === '..') {
                    throw new \RuntimeException('METHOD_C_INVALID_FINAL_NAME');
                }
                self::$plan[] = [
                    'occurrence' => (int)$entry_id . ':' . $ordinal,
                    'entry_id' => (int)$entry_id,
                    'ordinal' => $ordinal,
                    'source' => $path,
                    'sha256' => $digest,
                    'candidate' => $candidate,
                ];
            }
        }
        if (!self::$plan) {
            throw new \RuntimeException('METHOD_C_NO_ELIGIBLE_OCCURRENCES');
        }
        // Reserve every original candidate: collision suffixes must not steal a
        // later naturally noncolliding name. A simple one-pass suffix is insufficient.
        $reserved = array_fill_keys(array_column(self::$plan, 'candidate'), true);
        $used = [];
        foreach (self::$plan as &$item) {
            $name = $item['candidate'];
            if (isset($used[$name])) {
                $stem = pathinfo($name, PATHINFO_FILENAME);
                $extension = pathinfo($name, PATHINFO_EXTENSION);
                $extension = $extension === '' ? '' : '.' . $extension;
                $allocated = false;
                for ($n = 1; $n <= 10000; $n++) {
                    $suffix = sanitize_file_name(
                        $stem . '-e' . $item['entry_id'] . '-o' . $item['ordinal'] . '-' . $n . $extension
                    );
                    if ($suffix !== '' && !isset($reserved[$suffix]) && !isset($used[$suffix])) {
                        $name = $suffix;
                        $allocated = true;
                        break;
                    }
                }
                if (!$allocated) {
                    throw new \RuntimeException('METHOD_C_COLLISION_UNRESOLVABLE');
                }
            }
            if (str_contains($name, '/') || str_contains($name, '\\') ||
                isset($used[$name]) || $zip->locateName($name) !== false) {
                throw new \RuntimeException('METHOD_C_ZIP_MEMBER_ALREADY_EXISTS');
            }
            if (getenv('BRC_MC_FAIL_ADD') === '1') {
                throw new \RuntimeException('METHOD_C_INJECTED_ADD_FAILURE');
            }
            // Fifth argument explicitly disables ZipArchive's default FL_OVERWRITE.
            if (!$zip->addFile($item['source'], $name, 0, 0, 0)) {
                throw new \RuntimeException('METHOD_C_ZIP_ADD_FAILED');
            }
            $item['member'] = $name;
            $used[$name] = true;
        }
        unset($item);
        $lab = getenv('BRC_LAB_DIR');
        $case = getenv('BRC_MC_SCENARIO');
        if ($lab && $case && preg_match('/^[a-z0-9_]+$/D', $case)) {
            file_put_contents($lab . '/mc-' . $case . '.json',
                wp_json_encode(['occurrences' => self::$plan], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        return $zip;
    }

    public static function verify_archive(string $filename): void {
        $zip = new ZipArchive();
        if ($zip->open($filename) !== true) {
            throw new \RuntimeException('METHOD_C_FINAL_ARCHIVE_OPEN_FAILED');
        }
        try {
            if ($zip->numFiles !== count(self::$plan)) {
                throw new \RuntimeException('METHOD_C_POST_HOOK_UNREGISTERED_OR_MISSING_MEMBER');
            }
            $expected = [];
            foreach (self::$plan as $item) {
                $expected[$item['member']] = $item;
            }
            if (count($expected) !== count(self::$plan)) {
                throw new \RuntimeException('METHOD_C_DUPLICATE_PLANNED_MEMBER');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $member = $zip->getNameIndex($i);
                $bytes = $zip->getFromIndex($i);
                if (!is_string($member) || !is_string($bytes) || str_contains($member, '/') ||
                    !isset($expected[$member])) {
                    throw new \RuntimeException('METHOD_C_POST_HOOK_UNREGISTERED_MEMBER');
                }
                $source = $expected[$member];
                $source_hash = hash_file('sha256', $source['source']);
                if ($source_hash !== $source['sha256'] ||
                    hash('sha256', $bytes) !== $source['sha256']) {
                    throw new \RuntimeException('METHOD_C_POST_HOOK_HASH_OR_SOURCE_MISMATCH');
                }
                unset($expected[$member]);
            }
            if ($expected) {
                throw new \RuntimeException('METHOD_C_MISSING_SOURCE_OCCURRENCE');
            }
        } finally {
            $zip->close();
        }
    }
}
