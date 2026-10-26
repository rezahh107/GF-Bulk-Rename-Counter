<?php
/**
 * Real WordPress/GF regression for locale-independent NEW zero-file handling.
 * Only disposable synthetic Entries are created; no source uploads are changed.
 */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Authentic WordPress/Gravity Forms required');
}
$domain = 'gf-bulk-rename-counter';
$expected_locale = getenv('BRC_EXPECT_LOCALE');
if (!in_array($expected_locale, ['en_US', 'fa_IR'], true) || get_locale() !== $expected_locale) {
    throw new RuntimeException('Actual WordPress locale differs from expected: ' .
        (string)$expected_locale . ' vs ' . get_locale());
}
$form_id = GFAPI::add_form([
    'title' => 'GFBRC synthetic zero-file i18n',
    'fields' => [['id' => 1, 'type' => 'fileupload', 'label' => 'Upload']],
    'button' => ['type' => 'text', 'text' => 'Submit'],
]);
if (is_wp_error($form_id) || (int)$form_id < 1) {
    throw new RuntimeException('Unable to create isolated zero-file form');
}
$entry_id = GFAPI::add_entry(['form_id' => (int)$form_id, '1' => '']);
if (is_wp_error($entry_id) || (int)$entry_id < 1) {
    throw new RuntimeException('Unable to create isolated zero-file Entry');
}
$plan_method = new ReflectionMethod(\GFBulkRename\New_Download::class, 'plan');
$all_plan_method = new ReflectionMethod(\GFBulkRename\All_Download::class, 'plan');
        try {
            \GFBulkRename\Flat_Zip_Download::plan((int)$form_id, [(int)$entry_id]);
            throw new RuntimeException('Flat ZIP accepted a zero-file selection');
        } catch (\GFBulkRename\No_Eligible_Files_Exception $error) {
            if ($error->getMessage() !== __('No eligible files found', $domain)) {
                throw new RuntimeException('Wrong translated empty-plan reason');
            }
        }
        if ($plan_method->invoke(null, (int)$form_id, [(int)$entry_id]) !== []) {
            throw new RuntimeException('NEW zero-file selection not preserved');
        }
        if ($all_plan_method->invoke(null, (int)$form_id, [(int)$entry_id]) !== []) {
            throw new RuntimeException('ALL zero-file selection not preserved');
        }
        // Actual failures must not be reinterpreted as empty file selections.
        try {
            $plan_method->invoke(null, 0, [(int)$entry_id]);
            throw new RuntimeException('Unrelated invalid-form error was hidden');
        } catch (\GFBulkRename\No_Eligible_Files_Exception $error) {
            throw new RuntimeException('Nonempty error was swallowed as zero files', 0, $error);
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== __('Form unavailable', $domain)) {
                throw $error;
            }
        }
        try {
            $all_plan_method->invoke(null, 0, [(int)$entry_id]);
            throw new RuntimeException('ALL invalid-form error was hidden');
        } catch (\GFBulkRename\No_Eligible_Files_Exception $error) {
            throw new RuntimeException('ALL error was swallowed as zero files', 0, $error);
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== __('Form unavailable', $domain)) {
                throw $error;
            }
        }
echo 'PASS_REAL_WP_GF_NEW_ALL_ZERO_FILE_TYPED_ERROR_LOCALE_' . $expected_locale . PHP_EOL;
