<?php
/** Real WordPress gettext and GF naming-label fallback: no stored data edits. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Genuine WordPress and Gravity Forms required');
}
$locale = getenv('BRC_EXPECT_LOCALE');
if (!in_array($locale, ['fa_IR', 'en_US'], true) || get_locale() !== $locale) {
    throw new RuntimeException('Unexpected active locale for field fallback');
}
$form = ['fields' => [
    (object) ['id' => 5, 'type' => 'text', 'label' => 'نام دانش‌آموز'],
    (object) ['id' => 6, 'type' => 'text', 'label' => null],
    (object) ['id' => 7, 'type' => 'number', 'label' => ''],
    (object) ['id' => 8, 'type' => 'textarea', 'label' => " \t \n "],
    (object) ['id' => 9, 'type' => 'text', 'label' => "\u{00A0}\u{3000}"],
    (object) ['id' => 10, 'type' => 'number', 'label' => '  Custom Name  '],
    (object) ['id' => 11, 'type' => 'fileupload', 'label' => 'Not eligible'],
]];
$labels = \GFBulkRename\Form_Naming_Rules::eligible_fields($form);
$expected = [
    5 => 'نام دانش‌آموز',
    6 => sprintf(__('Field %d', 'gf-bulk-rename-counter'), 6),
    7 => sprintf(__('Field %d', 'gf-bulk-rename-counter'), 7),
    8 => sprintf(__('Field %d', 'gf-bulk-rename-counter'), 8),
    9 => sprintf(__('Field %d', 'gf-bulk-rename-counter'), 9),
    10 => '  Custom Name  ',
];
if ($labels !== $expected || ($locale === 'fa_IR' && $labels[6] !== 'فیلد 6') ||
    ($locale === 'en_US' && $labels[6] !== 'Field 6')) {
    throw new RuntimeException('Actual locale field labels do not match: ' . wp_json_encode($labels));
}
echo 'PASS_REAL_' . $locale . '_ELIGIBLE_FIELD_LABEL_FALLBACK_NULL_EMPTY_WHITESPACE_UNICODE_AND_NORMAL' . PHP_EOL;
