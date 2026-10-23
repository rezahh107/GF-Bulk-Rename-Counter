<?php
/**
 * Qualification-only differential for GF's documented Entry List filters.
 * Loaded solely into the ephemeral native CI WordPress mu-plugins directory.
 */
if (!defined('ABSPATH')) { return; }

function gfbrc_method_lab(): array {
    $lab = getenv('BRC_LAB_DIR');
    $file = $lab ? $lab . '/hook-fixture.json' : '';
    return ($file && is_file($file)) ? (json_decode(file_get_contents($file), true) ?: []) : [];
}
function gfbrc_method_case(): string {
    $case = $_GET['gfbrc_method_case'] ?? $_POST['gfbrc_method_case'] ?? '';
    return is_string($case) ? $case : '';
}
function gfbrc_method_select_group(string $case): string {
    if ($case === 'context') {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? 'hook-b' : 'hook-a';
    }
    if ($case === 'user_policy') {
        return get_user_meta(get_current_user_id(), 'gfbrc_method_allowed_cohort', true) === 'hook-b' ? 'hook-b' : 'hook-a';
    }
    if ($case === 'policy') {
        $lab = getenv('BRC_LAB_DIR');
        $p = $lab ? $lab . '/method-policy.txt' : '';
        return $p && is_file($p) && trim(file_get_contents($p)) === 'hook-b' ? 'hook-b' : 'hook-a';
    }
    return 'hook-a';
}
function gfbrc_method_normalize($args, int $expected): bool {
    if (!is_array($args) || array_keys($args) !== ['form_id','search_criteria','sorting','paging'] ||
        ($args['form_id'] ?? null) != $expected ||
        !is_array($args['search_criteria']) ||
        !in_array($args['search_criteria']['status'] ?? null, ['active','spam','trash'], true) ||
        !is_array($args['sorting']) || !is_array($args['paging'])) {
        return false;
    }
    $criteria = $args['search_criteria'];
    if (array_diff(array_keys($criteria), ['status','field_filters','start_date','end_date'])) {
        return false;
    }
    if (isset($criteria['field_filters'])) {
        if (!is_array($criteria['field_filters'])) { return false; }
        foreach ($criteria['field_filters'] as $filter) {
            if (!is_array($filter) || !isset($filter['key']) || !isset($filter['value']) ||
                !is_scalar($filter['key']) || !is_scalar($filter['value']) ||
                array_diff(array_keys($filter), ['key','value','operator'])) {
                return false;
            }
        }
    }
    return true;
}
function gfbrc_method_ids(int $form_id, array $criteria): array {
    $total = 0;
    $ids = GFAPI::get_entry_ids($form_id, $criteria,
        ['key' => 'id', 'direction' => 'ASC', 'is_numeric' => true],
        ['offset' => 0, 'page_size' => 100], $total);
    if (!is_array($ids) || $total > 500 || count($ids) !== $total) {
        throw new RuntimeException('Test-only capped GFAPI ID enumeration failed');
    }
    $ids = array_map('intval', $ids);
    sort($ids, SORT_NUMERIC);
    return $ids;
}

add_action('init', static function () {
    $fixture = gfbrc_method_lab();
    $target = (int)($fixture['form_id'] ?? 0);
    if (!$target) { return; }
    // Hook #1: documented search criteria hook, executed inside get_search_criteria.
    add_filter('gform_search_criteria_entry_list', static function ($criteria, $form_id = null) use ($target) {
        if ((int)$form_id !== $target || !in_array(gfbrc_method_case(), ['stacked','multi'], true)) {
            return $criteria;
        }
        $criteria['field_filters'][] = ['key' => '6', 'value' => 'allow'];
        return $criteria;
    }, 12, 2);

    // Hook #2: documented args hook, executed later, immediately before GFAPI get_entries.
    add_filter('gform_get_entries_args_entry_list', static function ($args) use ($target) {
        $case = gfbrc_method_case();
        if ((int)($args['form_id'] ?? 0) !== $target ||
            !in_array($case, ['single','stacked','multi','same_count','context','policy','user_policy','unsupported','shape_drift','malformed_criteria'], true)) {
            return $args;
        }
        $group = $case === 'same_count' ? 'hook-b' : gfbrc_method_select_group($case);
        if ($case === 'unsupported' ||
            ($case === 'shape_drift' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')) {
            $args['form_id'] = 0; // Deliberately unsupported cross-form query shape.
        } elseif ($case === 'malformed_criteria' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $args['search_criteria']['field_filters'] = [['key' => '5', 'value' => new stdClass()]];
        } elseif (!in_array($case, ['shape_drift','malformed_criteria'], true)) {
            $args['search_criteria']['field_filters'][] = ['key' => '5', 'value' => $group];
        }
        return $args;
    }, 10, 1);

    // A third callback establishes deterministic composition on the same hook.
    add_filter('gform_get_entries_args_entry_list', static function ($args) use ($target) {
        if ((int)($args['form_id'] ?? 0) !== $target || gfbrc_method_case() !== 'stacked') {
            return $args;
        }
        $args['search_criteria']['field_filters'][] = ['key' => '6', 'value' => 'allow'];
        return $args;
    }, 20, 1);

    // A passive observer after all ordinary callback priorities; does not mutate GF's args.
    add_filter('gform_get_entries_args_entry_list_' . $target,
        static function ($args) use ($target) {
            $GLOBALS['gfbrc_method_native_args'] = $args;
            return $args;
        }, PHP_INT_MAX, 1);
}, 1);

add_action('admin_footer', static function () {
    $fixture = gfbrc_method_lab();
    $form_id = (int)($fixture['form_id'] ?? 0);
    $args = $GLOBALS['gfbrc_method_native_args'] ?? null;
    if (!$form_id || !is_array($args) || (int)($_GET['id'] ?? 0) !== $form_id) {
        return;
    }
    $case = gfbrc_method_case();
    $payload = ['form_id' => $form_id, 'user_id' => get_current_user_id(),
        'case' => $case, 'args' => $args,
        'nonce' => wp_create_nonce('gfbrc_method_qualify_' . $form_id)];
    $json = wp_json_encode($payload);
    $signature = hash_hmac('sha256', $json, wp_salt('auth'));
    echo '<script type="application/json" id="gfbrc-method-capture" data-payload="' .
        esc_attr($json) . '" data-seal="' . esc_attr($signature) . '"></script>';
});

add_action('admin_post_gfbrc_method_qualify', static function () {
    header('Content-Type: application/json');
    $fixture = gfbrc_method_lab();
    $form_id = (int)($fixture['form_id'] ?? 0);
    $raw = $_POST['payload'] ?? null;
    $signature = $_POST['seal'] ?? null;
    $respond = static function (array $response, int $code = 200) {
        status_header($code);
        echo wp_json_encode($response);
        exit;
    };
    if (!is_string($raw) || !is_string($signature) ||
        !hash_equals(hash_hmac('sha256', wp_unslash($raw), wp_salt('auth')), $signature)) {
        $respond(['state' => 'BLOCK_SIGNATURE'], 403);
    }
    $p = json_decode(wp_unslash($raw), true);
    if (!is_array($p) || !$form_id || (int)($p['form_id'] ?? 0) !== $form_id ||
        (int)($p['user_id'] ?? 0) !== get_current_user_id() ||
        !GFCommon::current_user_can_any('gravityforms_view_entries') ||
        !wp_verify_nonce($p['nonce'] ?? '', 'gfbrc_method_qualify_' . $form_id)) {
        $respond(['state' => 'BLOCK_AUTH_CONTEXT'], 403);
    }
    $case = $p['case'] ?? '';
    if (!is_string($case) || $case !== gfbrc_method_case() ||
        !in_array($case, ['none','single','stacked','multi','same_count','context','policy','user_policy','shape_drift','malformed_criteria'], true)) {
        $respond(['state' => 'BLOCK_CONTEXT'], 403);
    }
    if (!gfbrc_method_normalize($p['args'], $form_id)) {
        $respond(['state' => 'BLOCK_CAPTURE_SHAPE'], 422);
    }
    $original = $_GET;
    try {
        $_GET = ['page' => 'gf_entries', 'id' => (string)$form_id,
            'gfbrc_method_case' => $case];
        if (!class_exists('GF_Entry_List_Table', false)) {
            require_once GFCommon::get_base_path() . '/entry_list.php';
        }
        $table = new GF_Entry_List_Table(['form_id' => $form_id, 'filter' => '']);
        $criteria = $table->get_search_criteria();
        // Method A, with display's signed sorting/paging for callback input only.
        $current = gf_apply_filters(['gform_get_entries_args_entry_list', $form_id],
            ['form_id' => $form_id, 'search_criteria' => $criteria,
             'sorting' => $p['args']['sorting'], 'paging' => $p['args']['paging']]);
    } finally {
        $_GET = $original;
    }
    if (!gfbrc_method_normalize($current, $form_id)) {
        $respond(['state' => 'BLOCK_CURRENT_SHAPE'], 422);
    }
    try {
        $display_ids = gfbrc_method_ids($form_id, $p['args']['search_criteria']);
        $current_ids = gfbrc_method_ids($form_id, $current['search_criteria']);
    } catch (Throwable $e) {
        $respond(['state' => 'BLOCK_QUERY'], 422);
    }
    $same = $display_ids === $current_ids;
    $respond(['state' => $same ? 'ALLOW_SAME_MEMBERSHIP' : 'BLOCK_MEMBERSHIP_CHANGED',
        'display_ids' => $display_ids, 'current_ids' => $current_ids,
        'display_count' => count($display_ids), 'current_count' => count($current_ids),
        'canonical_criteria_same' => $p['args']['search_criteria'] === $current['search_criteria'],
    ]);
});
