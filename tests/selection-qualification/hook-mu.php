<?php
/** Test-only documented Gravity Forms Entry List filter; never shipped in GFBRC. */
if (!defined('ABSPATH')) { return; }
add_filter('gform_get_entries_args_entry_list', static function ($args, $form_id = 0) {
    $lab = getenv('BRC_LAB_DIR');
    $file = $lab ? $lab . '/hook-fixture.json' : '';
    if (!$file || !is_file($file) || !is_array($args)) { return $args; }
    $fixture = json_decode(file_get_contents($file), true);
    if (!is_array($fixture) || (int)($args['form_id'] ?? $form_id) !== (int)$fixture['form_id']) {
        return $args;
    }
    if (!isset($args['search_criteria']['field_filters']) ||
        !is_array($args['search_criteria']['field_filters'])) {
        $args['search_criteria']['field_filters'] = [];
    }

    $case = $_GET['gfbrc_lab_case'] ?? '';
    $group = 'hook-a';
    if ($case === 'policy') {
        $group = get_user_meta(get_current_user_id(), 'gfbrc_hook_allowed', true) === 'hook-b' ?
            'hook-b' : 'hook-a';
    } elseif ($case === 'context' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $group = 'hook-b';
    }
    $args['search_criteria']['field_filters'][] = [
        'key' => '5', 'value' => $group,
        'operator' => $case === 'same_membership' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ?
            'contains' : 'is',
    ];
    file_put_contents($lab . '/hook-fired.log',
        'FORM=' . $fixture['form_id'] . ' FILTER=hook-a' . PHP_EOL,
        FILE_APPEND | LOCK_EX);
    return $args;
}, 10, 2);


// Second documented callback establishes real composition and the expected
// order-dependent subset (2 of the 3 cohort-A Entries).
add_filter('gform_get_entries_args_entry_list', static function ($args) {
    if (($_GET['gfbrc_lab_case'] ?? '') === 'composed') {
        $args['search_criteria']['field_filters'][] = ['key' => '6', 'value' => 'allow'];
    }
    return $args;
}, 20, 1);

// Deliberate observer bypass: register a form-specific maximum-priority
// callback *after* GFBRC's observer is installed at admin_init priority 1.
add_action('admin_init', static function () {
    if (($_GET['gfbrc_lab_case'] ?? '') !== 'late') { return; }
    $fixture = gfbrc_method_lab_fallback();
    $form_id = (int)($fixture['form_id'] ?? 0);
    if (!$form_id) { return; }
    add_filter('gform_get_entries_args_entry_list_' . $form_id, static function ($args) {
        $args['search_criteria']['field_filters'][] = ['key' => '5', 'value' => 'hook-b'];
        return $args;
    }, PHP_INT_MAX, 1);
}, 2);
function gfbrc_method_lab_fallback(): array {
    $lab = getenv('BRC_LAB_DIR');
    $file = $lab ? $lab . '/hook-fixture.json' : '';
    return $file && is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
}
