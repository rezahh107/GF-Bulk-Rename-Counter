<?php
/**
 * TEST-ONLY hook adapter. Never loaded by the shipped GFBRC plugin.
 * It stages *each callback occurrence* under its entry ID before the native
 * addFile(), then renames the existing archive's indices at the final hook.
 */
final class GFBRC_Staged_Flatten_Experiment {
    private array $seen = [];
    private array $plan = [];
    private int $serial = 0;
    private string $scenario;

    public function __construct(string $scenario) {
        $this->scenario = $scenario;
    }

    public function register(): void {
        // Existing GFBRC priority 10 and the test priority-999 late filter run first.
        add_filter('bdfgf_entry_filename', [$this, 'stage'], 1000, 3);
        add_action('bdfgf_after_uploaded_files', [$this, 'flatten'], 10, 3);
    }

    public function stage($suggestion, $entry_id, $source): string {
        if (!is_string($source) || !is_file($source) || !is_readable($source)) {
            throw new RuntimeException('SF_SOURCE_UNAVAILABLE');
        }
        $sha = hash_file('sha256', $source);
        if (!is_string($sha)) {
            throw new RuntimeException('SF_SOURCE_HASH_FAILED');
        }
        $basename = sanitize_file_name(wp_basename((string) $suggestion));
        if ($basename === '' || $basename === '.' || $basename === '..') {
            throw new RuntimeException('SF_INVALID_ROOT_CANDIDATE');
        }
        $entry = (int) $entry_id;
        $ordinal = $this->seen[$entry] ?? 0;
        $this->seen[$entry] = $ordinal + 1;
        // Stage identity does not depend on source filename or its physical path.
        // Host's final sanitizer preserves these ASCII member components.
        $staged = $entry . '/gfb-stage-' . $this->serial++ . '-' . $ordinal . '.bin';
        $this->plan[] = [
            'entry_id' => $entry, 'ordinal' => $ordinal,
            'source' => $source, 'sha256' => $sha,
            'candidate' => $basename, 'stage' => $staged,
        ];
        return $staged;
    }

    public function flatten($zip, $uploaded_files, $form): void {
        if (!$zip instanceof ZipArchive) {
            throw new RuntimeException('SF_NOT_NATIVE_ZIP');
        }
        if (!$this->plan) {
            throw new RuntimeException('SF_EMPTY_PLAN');
        }
        // This verifies host-collected writer inputs, not eligibility of files
        // silently discarded by the host collector before any naming filter.
        $expected = [];
        foreach ($uploaded_files as $entry_id => $sources) {
            foreach ($sources as $source) {
                if (is_string($source) && is_file($source) && is_readable($source)) {
                    $key = (int) $entry_id . "\0" . $source;
                    $expected[$key] = ($expected[$key] ?? 0) + 1;
                }
            }
        }
        $actual = [];
        foreach ($this->plan as $item) {
            $key = $item['entry_id'] . "\0" . $item['source'];
            $actual[$key] = ($actual[$key] ?? 0) + 1;
        }
        if ($expected !== $actual) {
            throw new RuntimeException('SF_HOST_WRITER_OCCURRENCES_DIFFER');
        }
        if ($zip->numFiles !== count($this->plan)) {
            throw new RuntimeException('SF_PRE_FLATTEN_MEMBER_COUNT_MISMATCH');
        }
        $indices = [];
        foreach ($this->plan as $item) {
            $index = $zip->locateName($item['stage']);
            if ($index === false || isset($indices[$index])) {
                throw new RuntimeException('SF_STAGE_MISSING_OR_OVERWRITTEN');
            }
            $indices[$index] = true;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || !in_array($name, array_column($this->plan, 'stage'), true)) {
                throw new RuntimeException('SF_UNREGISTERED_PRE_FLATTEN_MEMBER');
            }
        }
        // Reserve all naturally occurring candidates before generating any
        // suffix, case-insensitively for portable extraction on Windows.
        $reserved = [];
        foreach ($this->plan as $item) {
            $reserved[strtolower($item['candidate'])] = true;
        }
        $used = [];
        foreach ($this->plan as &$item) {
            $name = $item['candidate'];
            if (isset($used[strtolower($name)])) {
                $stem = pathinfo($name, PATHINFO_FILENAME);
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $ext = $ext === '' ? '' : '.' . $ext;
                $selected = false;
                for ($n = 1; $n <= 10000; $n++) {
                    $new = sanitize_file_name(
                        $stem . '-e' . $item['entry_id'] . '-o' . $item['ordinal'] . '-' . $n . $ext
                    );
                    if ($new !== '' && !isset($reserved[strtolower($new)]) &&
                        !isset($used[strtolower($new)])) {
                        $name = $new;
                        $selected = true;
                        break;
                    }
                }
                if (!$selected) {
                    throw new RuntimeException('SF_NO_UNIQUE_ROOT_NAME');
                }
            }
            if ($name === '' || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
                throw new RuntimeException('SF_INVALID_ROOT_PATH');
            }
            $used[strtolower($name)] = true;
            $item['member'] = $name;
        }
        unset($item);
        $lab = getenv('BRC_LAB_DIR');
        if ($lab && preg_match('/^[a-z0-9_]+$/D', $this->scenario)) {
            file_put_contents($lab . '/sf-' . $this->scenario . '.json',
                wp_json_encode(['occurrences' => $this->plan], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        // Guard against further extensions already present before our hook.
        // Cannot prevent callbacks registered *after* this callback.
        foreach ($this->plan as $item) {
            $index = $zip->locateName($item['stage']);
            if ($index === false) {
                throw new RuntimeException('SF_STAGE_DISAPPEARED');
            }
            if ($zip->locateName($item['member']) !== false) {
                throw new RuntimeException('SF_ROOT_ALREADY_OCCUPIED');
            }
            if (getenv('BRC_SF_INJECT_RENAME_FAILURE') === '1') {
                throw new RuntimeException('SF_INJECTED_RENAME_FAILURE');
            }
            if (!$zip->renameIndex($index, $item['member'])) {
                throw new RuntimeException('SF_RENAME_INDEX_FAILED');
            }
            if ($zip->locateName($item['member']) === false) {
                throw new RuntimeException('SF_RENAME_NOT_VISIBLE');
            }
        }
        if ($zip->numFiles !== count($this->plan)) {
            throw new RuntimeException('SF_POST_FLATTEN_COUNT_MISMATCH');
        }
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || str_contains($name, '/') || str_contains($name, '\\') ||
                isset($names[$name])) {
                throw new RuntimeException('SF_POST_FLATTEN_INVALID_MEMBER');
            }
            $names[$name] = true;
        }
        if (array_diff(array_column($this->plan, 'member'), array_keys($names))) {
            throw new RuntimeException('SF_POST_FLATTEN_MEMBER_MISSING');
        }
    }
}
