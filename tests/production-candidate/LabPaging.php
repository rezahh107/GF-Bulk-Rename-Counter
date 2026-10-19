<?php
/** Disposable test-only page-size accommodation. Not shipped in plugin ZIP. */
if (!defined('ABSPATH')) { exit; }
if (getenv('BRC_LAB_DIR')) {
    add_filter('gform_entry_page_size', static function($size, $form_id) {
        if (is_file(getenv('BRC_LAB_DIR') . '/ifz-native-page-size')) { return $size; }
        return 160;
    }, 10, 2);
}
