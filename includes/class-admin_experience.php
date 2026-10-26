<?php
/**
 * Read-only administration and Entry List guidance for existing naming rules.
 *
 * Never changes saved rules, Entry data, download selection or NEW coverage.
 */
namespace GFBulkRename;

if (!defined('ABSPATH')) { exit; }

final class Admin_Experience {
    public static function settings_url(int $form_id = 0): string {
        return admin_url('options-general.php?page=gf-bulk-rename-settings' .
            ($form_id > 0 ? '&gfbrc_form=' . $form_id : ''));
    }

    public static function plugin_links(array $links): array {
        if (current_user_can('manage_options')) {
            array_unshift($links, '<a href="' . esc_url(self::settings_url()) . '">' .
                esc_html__('Settings', 'gf-bulk-rename-counter') . '</a>');
        }
        return $links;
    }

    /** Native GF toolbar on form editor, settings and Entries screens. */
    public static function gf_toolbar(array $items, $form_id): array {
        if (!current_user_can('manage_options')) { return $items; }
        $id = is_scalar($form_id) ? absint($form_id) : 0;
        $items['gfbrc_settings'] = [
            'label' => __('File naming settings', 'gf-bulk-rename-counter'),
            'title' => __('Configure GF Bulk Rename for this form', 'gf-bulk-rename-counter'),
            'url' => self::settings_url($id),
            'capabilities' => ['manage_options'],
            'priority' => 500,
        ];
        return $items;
    }

    /**
     * Explains exactly the configured naming choice. A configured method is
     * not a promise that every Entry has a usable value or an eligible upload.
     */
    public static function status(array $form): array {
        $id = (int)($form['id'] ?? 0);
        $saved = get_option(Form_Naming_Rules::OPTION_NAME, []);
        if (!is_array($saved)) {
            return ['warning',
                __('Per-form rules need attention', 'gf-bulk-rename-counter'),
                __('Saved per-form rules cannot be read. Ask an administrator to inspect the configuration before downloading.', 'gf-bulk-rename-counter')];
        }
        if (array_key_exists((string)$id, $saved)) {
            try {
                $rule = Form_Naming_Rules::for_form($form);
            } catch (\Throwable $e) {
                return ['warning',
                    __('Per-form rule needs repair', 'gf-bulk-rename-counter'),
                    __('The saved naming fields or order are no longer valid. Reopen and save this form rule before using independent ZIP downloads.', 'gf-bulk-rename-counter')];
            }
            if ($rule && $rule['method'] === 'single') {
                $labels = Form_Naming_Rules::eligible_fields($form);
                return ['ready',
                    __('Independent ZIP: Single Field', 'gf-bulk-rename-counter'),
                    sprintf(__('Filenames use %1$s (field %2$d). An empty or unsafe Entry value stops ZIP preparation; it does not silently fall back to the original name.', 'gf-bulk-rename-counter'),
                        $labels[$rule['field_id']], $rule['field_id'])];
            }
            if ($rule && $rule['method'] === 'composite') {
                $labels = Form_Naming_Rules::eligible_fields($form);
                $parts = [];
                foreach ($rule['field_ids'] as $field_id) {
                    $parts[] = sprintf(__('%1$s (field %2$d)', 'gf-bulk-rename-counter'),
                        $labels[$field_id], $field_id);
                }
                return ['ready',
                    __('Independent ZIP: Composite', 'gf-bulk-rename-counter'),
                    sprintf(__('Filenames combine these fields in order: %1$s. Separator: %2$s. Missing or unsafe values stop ZIP preparation.', 'gf-bulk-rename-counter'),
                        implode(' → ', $parts), $rule['separator'] === '' ?
                            __('(none)', 'gf-bulk-rename-counter') : $rule['separator'])];
            }
        }
        $global = get_option(Settings_Page::OPTION_NAME, []);
        if (!is_array($global)) { $global = []; }
        $counter = (int)($global['counter_field_id'] ?? 0);
        $scope = (int)($global['form_id'] ?? 0);
        $name = array_key_exists((string)$id, $saved)
            ? __('Independent ZIP: explicit Legacy rule', 'gf-bulk-rename-counter')
            : __('Independent ZIP: inherited Legacy defaults', 'gf-bulk-rename-counter');
        if ($counter <= 0) {
            return ['unchanged', $name,
                __('No Counter Field ID is configured. Original filenames are intentional for Legacy and inherited independent ZIP downloads.', 'gf-bulk-rename-counter')];
        }
        if ($scope > 0 && $scope !== $id) {
            return ['unchanged', $name,
                sprintf(__('Legacy targets form %d, not this form. Original filenames here are expected.', 'gf-bulk-rename-counter'), $scope)];
        }
        $field = \GFAPI::get_field($form, $counter);
        if (!is_object($field)) {
            return ['warning', $name,
                sprintf(__('Counter Field ID %d does not exist on this form. Legacy cannot rename these files; correct the global setting.', 'gf-bulk-rename-counter'), $counter)];
        }
        return ['ready', $name,
            sprintf(__('Legacy uses %1$s (field %2$d) and generates d[COUNTER].extension. Entries with an empty or zero counter retain their original filename.', 'gf-bulk-rename-counter'),
                (string)($field->label ?? __('Untitled field', 'gf-bulk-rename-counter')), $counter)];
    }

    public static function render_status(array $form): void {
        [$state, $title, $detail] = self::status($form);
        $css = $state === 'warning' ? 'notice-warning' : 'notice-info';
        echo '<div class="notice ' . esc_attr($css) . ' inline gfbrc-rule-status" data-gfbrc-status="' .
            esc_attr($state) . '"><p><strong>' . esc_html($title) .
            '</strong> — ' . esc_html($detail) . '</p>';
        if ($state === 'unchanged') {
            echo '<p>' . esc_html__('Unchanged filenames here reflect the current configuration, not proof of a download failure. Individual missing Entry values can also leave Legacy names unchanged.', 'gf-bulk-rename-counter') . '</p>';
        }
        if ($state === 'warning') {
            echo '<p>' . esc_html__('Configuration needs attention before renamed downloads can be expected.', 'gf-bulk-rename-counter') . '</p>';
        }
        if (current_user_can('manage_options')) {
            echo '<p><a href="' . esc_url(self::settings_url((int)$form['id'])) . '">' .
                esc_html__('Review naming settings for this form', 'gf-bulk-rename-counter') . '</a></p>';
        } elseif ($state !== 'ready') {
            echo '<p>' . esc_html__('Ask an administrator to review the naming settings.', 'gf-bulk-rename-counter') . '</p>';
        }
        echo '</div>';
    }

    public static function settings_intro(): void {
        echo '<p>' . esc_html__('First configure a Counter Field ID for Legacy downloads, or select a form below and save an independent Single Field / Composite rule. These are separate settings; saving one does not configure the other.', 'gf-bulk-rename-counter') . '</p>';
        echo '<p>' . esc_html__('Legacy applies through the original Bulk Download action. Selected, All matching, NEW and ALL use the independent ZIP rule for their form; without an override they inherit the Legacy counter and form scope.', 'gf-bulk-rename-counter') . '</p>';
    }

    /** Compact, read-only orientation adjacent to the native GF Entry actions. */
    public static function entries_footer(): void {
        if (($_GET['page'] ?? '') !== 'gf_entries' ||
            !class_exists('GFAPI') ||
            !\GFCommon::current_user_can_any('gravityforms_view_entries')) { return; }
        $id = isset($_GET['id']) && is_scalar($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$id) { return; }
        $form = \GFAPI::get_form($id);
        if (!is_array($form)) { return; }
        echo '<section id="gfbrc-entries-guide" aria-labelledby="gfbrc-guide-title">';
        echo '<h2 id="gfbrc-guide-title">' .
            esc_html__('File downloads and naming', 'gf-bulk-rename-counter') . '</h2>';
        self::render_status($form);
        echo '<details><summary>' . esc_html__('Which download should I use?', 'gf-bulk-rename-counter') .
            '</summary><ul>';
        foreach ([
            __('Selected: choose rows on this page, then use “Download Flat ZIP with Rename” in Bulk Actions. It includes eligible uploads only from those rows.', 'gf-bulk-rename-counter'),
            __('All matching: choose the all-matching checkbox with the independent Bulk Action. It includes the current search and filters across pages (maximum 500 Entries), not just checked rows.', 'gf-bulk-rename-counter'),
            __('NEW: check for newly created Entries, download and inspect the verified ZIP, then explicitly acknowledge receipt. Browser verification does not prove the ZIP was retained locally. The first check establishes the initial batch; acknowledgment advances coverage.', 'gf-bulk-rename-counter'),
            __('ALL: independently check and download eligible files from every active Entry in this form, ignoring search, row selection and NEW history. ALL does not acknowledge NEW.', 'gf-bulk-rename-counter'),
        ] as $instruction) {
            echo '<li>' . esc_html($instruction) . '</li>';
        }
        echo '</ul></details>';
        echo '<p class="description">' . esc_html__('Checking or downloading never assigns counter values. A missing Legacy counter may intentionally keep an original filename; invalid Single Field / Composite Entry values stop independent ZIP preparation instead.', 'gf-bulk-rename-counter') . '</p>';
        echo '</section>';
        ?>
<style id="gfbrc-guide-style">
#gfbrc-entries-guide {box-sizing:border-box;max-width:840px;min-width:0;margin:12px 0;padding:12px 16px;border:1px solid #c3c4c7;background:#fff}
#gfbrc-entries-guide h2 {margin:0 0 8px;font-size:1.1em}
#gfbrc-entries-guide summary {cursor:pointer;padding:5px 0}
#gfbrc-entries-guide ul {list-style:disc;padding-inline-start:1.6em;margin:8px 0}
#gfbrc-entries-guide li {margin-bottom:6px}
#gfbrc-entries-guide .notice {margin:8px 0}
@media(max-width:782px){#gfbrc-entries-guide{max-width:100%;padding:10px}}
</style>
<script id="gfbrc-guide-position">
(function () {
  var guide = document.getElementById('gfbrc-entries-guide');
  var apply = document.getElementById('doaction');
  if (guide && apply) {
    (apply.closest('.tablenav') || apply).insertAdjacentElement('afterend', guide);
  }
})();
</script>
        <?php
    }
}
