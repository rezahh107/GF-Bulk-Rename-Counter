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
        // Ordinary configuration state is a summary, not a WordPress warning.
        $classes = $state === 'warning' ? 'notice notice-warning inline ' : '';
        echo '<div class="' . $classes . 'gfbrc-rule-status gfbrc-status-' .
            esc_attr($state) . '" data-gfbrc-status="' . esc_attr($state) .
            '"><p class="gfbrc-status-heading"><strong>' . esc_html($title) .
            '</strong></p><p class="gfbrc-status-detail">' . esc_html($detail) . '</p>';
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
        echo '<p><strong>' . esc_html__('1. Which Entries should be downloaded?', 'gf-bulk-rename-counter') . '</strong> ' .
            esc_html__('Choose the download scope on the Gravity Forms Entries page.', 'gf-bulk-rename-counter') . '</p>';
        echo '<p><strong>' . esc_html__('2. What names should the downloaded files have?', 'gf-bulk-rename-counter') . '</strong> ' .
            esc_html__('Choose a naming rule below. Your original uploaded files are never renamed.', 'gf-bulk-rename-counter') . '</p>';
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
        echo '<p class="gfbrc-lead">' . esc_html__('First select which Entries to download. Naming is configured separately and changes only filenames inside the ZIP.', 'gf-bulk-rename-counter') . '</p>';
        echo '<details><summary>' . esc_html__('Which download should I use?', 'gf-bulk-rename-counter') .
            '</summary><ul>';
        foreach ([
            __('Selected: tick rows on this page; choose Download Flat ZIP with Rename from Bulk Actions, then Apply.', 'gf-bulk-rename-counter'),
            __('All matching: select all matching Entries with the checkbox near Bulk Actions; current filters apply across pages, up to 500 Entries.', 'gf-bulk-rename-counter'),
            __('NEW: check for an unconfirmed batch (the first batch may include existing Entries). Download and review; only explicit receipt confirmation moves NEW coverage.', 'gf-bulk-rename-counter'),
            __('ALL: check all active Entries in this form regardless of filters or selected rows. It does not change NEW coverage.', 'gf-bulk-rename-counter'),
        ] as $instruction) {
            echo '<li>' . esc_html($instruction) . '</li>';
        }
        echo '</ul></details>';
        echo '<h3 class="gfbrc-current-heading">' . esc_html__('How filenames will be generated for this form', 'gf-bulk-rename-counter') . '</h3>';
        self::render_status($form);
        echo '<p class="description">' . esc_html__('Legacy may keep original names for missing or zero counters. Invalid Single Field or Composite values stop independent ZIP preparation.', 'gf-bulk-rename-counter') . '</p>';
        echo '</section>';
        ?>
<style id="gfbrc-guide-style">
/* GFBRC-only workspace; never restyle native GF tablenav or other wp-admin forms. */
#gfbrc-entries-workspace{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:start;width:100%;max-width:980px;margin:16px 0}
#gfbrc-entries-workspace>section{box-sizing:border-box;min-width:0;margin:0;padding:18px 20px;border:1px solid #c3c4c7;background:#fff;overflow-wrap:anywhere}
#gfbrc-entries-guide{box-sizing:border-box;grid-column:1/-1;max-width:980px;margin:12px 0;padding:18px 20px;border:1px solid #c3c4c7;background:#fff;overflow-wrap:anywhere}
#gfbrc-entries-workspace #gfbrc-entries-guide{margin:0}
#gfbrc-entries-guide h2,#gfbrc-entries-workspace .gfbrc-panel-header h2{font-size:16px;font-weight:600;line-height:1.45;margin:0}
#gfbrc-entries-guide h2{margin-bottom:8px}
#gfbrc-entries-guide .gfbrc-current-heading{font-size:14px;font-weight:600;margin:16px 0 8px}
#gfbrc-entries-guide .gfbrc-rule-status{background:#f6f7f7;border:1px solid #dcdcde;border-inline-start:3px solid #8c8f94;margin:8px 0;padding:10px 14px;max-width:72ch}
#gfbrc-entries-guide .gfbrc-rule-status.gfbrc-status-warning{border-inline-start-color:#dba617;background:#fff9e5}
#gfbrc-entries-guide .gfbrc-rule-status p{line-height:1.65;margin:4px 0}
#gfbrc-entries-guide .gfbrc-status-heading{font-size:13px}
#gfbrc-entries-guide .gfbrc-status-detail{color:#50575e}
#gfbrc-entries-guide .gfbrc-lead,#gfbrc-entries-guide .description{max-width:72ch}
#gfbrc-entries-guide .gfbrc-lead{margin:0 0 14px;line-height:1.75}
#gfbrc-entries-guide summary{cursor:pointer;padding:8px 0}
#gfbrc-entries-guide ul{list-style:disc;padding-inline-start:1.6em;margin:8px 0}
#gfbrc-entries-guide li{margin-bottom:8px;line-height:1.7}
#gfbrc-entries-guide .notice{margin:12px 0;padding:8px 12px}
#gfbrc-entries-guide .notice p{margin:4px 0;line-height:1.75}
#gfbrc-entries-workspace .gfbrc-panel-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin:0 0 12px}
#gfbrc-entries-workspace .gfbrc-panel-header .button{flex-shrink:0;margin:0;min-height:32px;white-space:normal;line-height:1.4}
#gfbrc-entries-workspace .description{color:#50575e;line-height:1.75;margin:10px 0}
#gfbrc-entries-workspace .gfbrc-panel-intro{font-weight:500;line-height:1.6;margin:0 0 10px;max-width:68ch}
#gfbrc-entries-workspace #gfbrc-all-guide{max-width:68ch}
#gfbrc-entries-workspace .gfbrc-panel-result{margin:12px 0 0;line-height:1.7;min-height:0}
#gfbrc-entries-workspace .gfbrc-panel-result:empty{display:none}
#gfbrc-entries-workspace .gfbrc-panel-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:14px 0 0}
#gfbrc-entries-workspace .gfbrc-panel-actions:has(>[hidden]:only-child){display:none}
#gfbrc-entries-workspace #gfbrc-new-review-wrap{display:inline-flex;align-items:flex-start;gap:8px;line-height:1.7}
#gfbrc-entries-workspace #gfbrc-new-review-wrap input{margin:4px 0 0;flex-shrink:0}
#gfbrc-entries-workspace #gfbrc-new-guide{border-inline-start:3px solid #dba617;padding:10px 12px;background:#fff8e5;color:#3c434a}
#gfbrc-entries-workspace .button:focus-visible,#gfbrc-entries-guide summary:focus-visible{outline:2px solid #2271b1;outline-offset:2px}
#gfbrc-entries-workspace [hidden]{display:none!important}
#gfbrc-entries-guide bdi,#gfbrc-entries-workspace bdi{unicode-bidi:isolate}
@media(max-width:782px){#gfbrc-entries-workspace{grid-template-columns:minmax(0,1fr);gap:12px;max-width:100%}#gfbrc-entries-workspace>section,#gfbrc-entries-guide{padding:14px 12px;max-width:100%}}
</style>
<script id="gfbrc-guide-position">
(function () {
  var guide = document.getElementById('gfbrc-entries-guide');
  var apply = document.getElementById('doaction');
  if (!guide || !apply) return;
  var anchor = apply.closest('.tablenav') || apply;
  var workspace = document.getElementById('gfbrc-entries-workspace');
  if (!workspace) {
    workspace = document.createElement('div');
    workspace.id = 'gfbrc-entries-workspace';
    anchor.insertAdjacentElement('afterend', workspace);
  }
  workspace.insertAdjacentElement('afterbegin', guide);
})();
</script>
        <?php
    }
}
