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
    $args['search_criteria']['field_filters'][] = ['key' => '5', 'value' => 'hook-a'];
    file_put_contents($lab . '/hook-fired.log',
        'FORM=' . $fixture['form_id'] . ' FILTER=hook-a' . PHP_EOL,
        FILE_APPEND | LOCK_EX);
    return $args;
}, 10, 2);
