<?php
/**
 * Settings page handler using WordPress Settings API.
 *
 * @package GFBulkRename
 * @since 2.0.0
 */

namespace GFBulkRename;

if ( ! defined( 'ABSPATH' ) ) {
    exit;

}

class Settings_Page {

    public const OPTION_GROUP = 'gf_bulk_rename_settings';
    public const OPTION_NAME = 'gf_bulk_rename_options';

    public static function register_settings(): void {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            [
                'type'              => 'array',
                'sanitize_callback' => [ self::class, 'sanitize_options' ],
                'default'           => [
                    'form_id'          => 0,
                    'counter_field_id' => 0,
                ],
            ]
        );

        register_setting(Form_Naming_Rules::OPTION_GROUP, Form_Naming_Rules::OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [Form_Naming_Rules::class, 'sanitize'],
            'default' => [],
        ]);

        add_settings_section(
            'gf_bulk_rename_main',
            __( 'Bulk Download Rename Settings', 'gf-bulk-rename-counter' ),
            [ self::class, 'section_callback' ],
            'gf-bulk-rename-settings'
        );

        add_settings_field(
            'form_id',
            __( 'Target Form', 'gf-bulk-rename-counter' ),
            [ self::class, 'form_id_field' ],
            'gf-bulk-rename-settings',
            'gf_bulk_rename_main'
        );

        add_settings_field(
            'counter_field_id',
            __( 'Counter Field ID', 'gf-bulk-rename-counter' ),
            [ self::class, 'counter_field_id_field' ],
            'gf-bulk-rename-settings',
            'gf_bulk_rename_main'
        );
    }

    public static function sanitize_options( array $input ): array {
        return [
            'form_id'          => isset( $input['form_id'] ) ? absint( $input['form_id'] ) : 0,
            'counter_field_id' => isset( $input['counter_field_id'] ) ? absint( $input['counter_field_id'] ) : 0,
        ];
    }

    public static function section_callback(): void {
        echo '<p>' . esc_html__( 'Enter the ID of the field that already contains each Entry counter. For example, counter 1234 produces d1234.jpg in Legacy downloads. This plugin never creates counter values.', 'gf-bulk-rename-counter' ) . '</p>';
    }

    public static function form_id_field(): void {
        $options = get_option( self::OPTION_NAME, [ 'form_id' => 0, 'counter_field_id' => 0 ] );
        $current = $options['form_id'] ?? 0;

        $forms = [];
        if ( class_exists( 'GFAPI' ) ) {
            $forms = \GFAPI::get_forms();
        }
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[form_id]" id="gf_bulk_rename_form_id">
            <option value="0"><?php esc_html_e( '— Apply to all forms —', 'gf-bulk-rename-counter' ); ?></option>
            <?php foreach ( $forms as $form ) : ?>
                <option value="<?php echo esc_attr( $form['id'] ); ?>" <?php selected( $current, $form['id'] ); ?>>
                    <?php echo esc_html( $form['title'] . ' (ID: ' . $form['id'] . ')' ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Select a specific Gravity Form or leave as "All forms".', 'gf-bulk-rename-counter' ); ?></p>
        <?php
    }

    public static function counter_field_id_field(): void {
        $options = get_option( self::OPTION_NAME, [ 'form_id' => 0, 'counter_field_id' => 0 ] );
        $current = $options['counter_field_id'] ?? 0;
        ?>
        <input type="number" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[counter_field_id]" id="gf_bulk_rename_counter_field_id" value="<?php echo esc_attr( $current ); ?>" class="small-text" />
        <p class="description"><?php esc_html_e( 'Global Legacy Counter Field ID. 0 keeps original names. Set the target form above (or All forms); independent Single Field/Composite overrides are configured separately below.', 'gf-bulk-rename-counter' ); ?></p>
        <?php
        $preview_form_id = ! empty( $options['form_id'] ) ? absint( $options['form_id'] ) :
            ( isset( $_GET['gfbrc_form'] ) && is_scalar( $_GET['gfbrc_form'] ) ? absint( $_GET['gfbrc_form'] ) : 0 );
        if ( $preview_form_id && class_exists( 'GFAPI' ) ) {
            $selected_form = \GFAPI::get_form( $preview_form_id );
            if ( is_array( $selected_form ) ) {
                echo '<details><summary>' . esc_html( sprintf( __( 'Show Global Legacy field labels and IDs for %1$s (form ID %2$d)', 'gf-bulk-rename-counter' ),
                    (string) ( $selected_form['title'] ?? '' ), $preview_form_id ) ) . '</summary><ul>';
                foreach ( $selected_form['fields'] ?? [] as $field ) {
                    if ( ! is_object( $field ) ) { continue; }
                    echo '<li>' . esc_html( (string) ( $field->label ?? '' ) ) . ' — <bdi dir="ltr">ID: ' .
                        esc_html( (string) ( $field->id ?? '' ) ) . '</bdi></li>';
                }
                echo '</ul></details>';
            }
        }
        ?>
        <?php
        if ( $current > 0 && ! empty( $options['form_id'] ) && class_exists( 'GFAPI' ) ) {
            $form = \GFAPI::get_form( $options['form_id'] );
            if ( $form && ! \GFAPI::get_field( $form, $current ) ) {
                echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Warning: This field ID does not exist in the selected form.', 'gf-bulk-rename-counter' ) . '</p></div>';
            }
        }
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gf-bulk-rename-counter' ) );
        }
        ?>
        <div class="wrap gfbrc-settings">
            <h1><?php echo esc_html__( 'Gravity Forms Bulk Rename Settings', 'gf-bulk-rename-counter' ); ?></h1>
            <div class="gfbrc-settings-intro"><?php Admin_Experience::settings_intro(); ?></div>
            <?php settings_errors( Form_Naming_Rules::OPTION_NAME ); ?>
            <section class="gfbrc-settings-card gfbrc-settings-legacy" aria-label="<?php echo esc_attr__('Bulk Download Rename Settings', 'gf-bulk-rename-counter'); ?>">
            <form action="options.php" method="post">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( 'gf-bulk-rename-settings' );
                submit_button();
                ?>
            </form>
            </section>
            <?php self::render_per_form_rules(); ?>
            <?php self::render_presentation(); ?>
        </div>
        <?php
    }
    /** A second native Settings API form edits only the selected Gravity Form. */
    private static function render_per_form_rules(): void {
        echo '<section class="gfbrc-settings-card gfbrc-settings-per-form" aria-labelledby="gfbrc-per-form-heading"><h2 id="gfbrc-per-form-heading">' . esc_html__('Per-form naming (independent flat ZIP only)', 'gf-bulk-rename-counter') . '</h2>';
        echo '<p>' . esc_html__('Legacy settings above still control the original Bulk Download hook. Single Field and Composite rules affect ONLY Download Flat ZIP with Rename. Values in filenames may expose personal information to ZIP recipients.', 'gf-bulk-rename-counter') . '</p>';
        $forms = class_exists('GFAPI') ? \GFAPI::get_forms() : [];
        if (!is_array($forms)) { $forms = []; }
        $selected = isset($_GET['gfbrc_form']) ? absint($_GET['gfbrc_form']) : 0;
        echo '<form method="get" action="options-general.php">';
        echo '<input type="hidden" name="page" value="gf-bulk-rename-settings">';
        echo '<label for="gfbrc-form-select">' . esc_html__('Choose a form to edit', 'gf-bulk-rename-counter') . '</label> ';
        echo '<select id="gfbrc-form-select" name="gfbrc_form"><option value="0">' .
            esc_html__('— Select a form —', 'gf-bulk-rename-counter') . '</option>';
        foreach ($forms as $f) {
            echo '<option value="' . esc_attr((string)$f['id']) . '" ' . selected($selected, $f['id'], false) . '>' .
                esc_html($f['title'] . ' (ID: ' . $f['id'] . ')') . '</option>';
        }
        echo '</select> ';
        submit_button(__('Open form rule', 'gf-bulk-rename-counter'), 'secondary', '', false);
        echo '</form>';
        if (!$selected || !class_exists('GFAPI')) {
            echo '<p class="description">' . esc_html__('Choose a form to see its effective download naming rule and edit an optional independent override.', 'gf-bulk-rename-counter') . '</p></section>';
            return;
        }
        $form = \GFAPI::get_form($selected);
        if (!is_array($form)) {
            echo '<p>' . esc_html__('Form unavailable.', 'gf-bulk-rename-counter') . '</p></section>';
            return;
        }
        Admin_Experience::render_status($form);
        $fields = Form_Naming_Rules::eligible_fields($form);
        $rules = get_option(Form_Naming_Rules::OPTION_NAME, []);
        $stored = is_array($rules) ? ($rules[(string)$selected] ?? []) : [];
        $method = is_array($stored) ? ($stored['method'] ?? 'inherit') : 'inherit';
        $single = is_array($stored) ? ($stored['field_id'] ?? 0) : 0;
        $ordered = is_array($stored) && isset($stored['field_ids']) && is_array($stored['field_ids'])
            ? $stored['field_ids'] : [];
        $ranks = array_flip($ordered);
        $sep = is_array($stored) ? ($stored['separator'] ?? '-') : '-';
        $prefix = Form_Naming_Rules::OPTION_NAME;
        echo '<form method="post" action="options.php">';
        settings_fields(Form_Naming_Rules::OPTION_GROUP);
        echo '<input type="hidden" name="' . esc_attr($prefix) . '[_form_id]" value="' . esc_attr((string)$selected) . '">';
        echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row"><label for="gfbrc-method">' .
            esc_html__('Naming method', 'gf-bulk-rename-counter') . '</label></th><td>';
        echo '<select id="gfbrc-method" name="' . esc_attr($prefix) . '[_method]">';
        foreach ([
            'inherit' => __('Use existing Legacy defaults (remove override)', 'gf-bulk-rename-counter'),
            'legacy' => __('Legacy (existing counter configuration)', 'gf-bulk-rename-counter'),
            'single' => __('Single Field', 'gf-bulk-rename-counter'),
            'composite' => __('Composite', 'gf-bulk-rename-counter'),
        ] as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($method, $key, false) . '>' .
                esc_html($label) . '</option>';
        }
        echo '</select><p class="description">' .
            esc_html__('Legacy uses the unchanged global Counter Field ID and Target Form scope. Other forms keep their own rules.', 'gf-bulk-rename-counter') .
            '</p></td></tr>';
        echo '<tr class="gfbrc-method-row" data-gfbrc-for="single"><th scope="row"><label for="gfbrc-single">' .
            esc_html__('Single Field source', 'gf-bulk-rename-counter') . '</label></th><td>';
        echo '<select id="gfbrc-single" name="' . esc_attr($prefix) . '[_single_field_id]">';
        echo '<option value="0">' . esc_html__('— Choose a text or number field —', 'gf-bulk-rename-counter') . '</option>';
        foreach ($fields as $id => $label) {
            echo '<option value="' . esc_attr((string)$id) . '" ' . selected($single, $id, false) . '>' .
                esc_html($label . ' (ID: ' . $id . ')') . '</option>';
        }
        echo '</select><p class="description">' . esc_html__('Example: a File number field containing 1234 produces 1234.jpg in an independent ZIP. A missing or unsafe value stops ZIP preparation.', 'gf-bulk-rename-counter') . '</p></td></tr>';
        echo '<tr class="gfbrc-method-row" data-gfbrc-for="composite"><th scope="row">' . esc_html__('Composite fields and order', 'gf-bulk-rename-counter') .
            '</th><td><fieldset><legend class="screen-reader-text">' .
            esc_html__('Composite fields and order', 'gf-bulk-rename-counter') . '</legend>';
        echo '<p class="description">' .
            esc_html__('For Composite, check at least two eligible text/number fields and assign consecutive positions 1, 2, 3 … . Empty Entry values will block ZIP creation.', 'gf-bulk-rename-counter') . '</p>';
        echo '<p class="description">' . esc_html__('Example: ClassA followed by 1234 with separator - produces ClassA-1234.jpg. These are examples, not a preview of saved Entry values.', 'gf-bulk-rename-counter') . '</p>';
        foreach ($fields as $id => $label) {
            $rank = isset($ranks[$id]) ? $ranks[$id] + 1 : '';
            echo '<p class="gfbrc-composite-row"><label><input type="checkbox" name="' . esc_attr($prefix) . '[_include][' . esc_attr((string)$id) .
                ']" value="1" ' . checked(isset($ranks[$id]), true, false) . '> ' .
                esc_html($label . ' (ID: ' . $id . ')') . '</label> ';
            echo '<label for="gfbrc-order-' . esc_attr((string)$id) . '">' .
                esc_html__('Position', 'gf-bulk-rename-counter') . '</label> ';
            echo '<input id="gfbrc-order-' . esc_attr((string)$id) . '" type="number" min="1" step="1" class="small-text" name="' .
                esc_attr($prefix) . '[_field_order][' . esc_attr((string)$id) . ']" value="' .
                esc_attr((string)$rank) . '"></p>';
        }
        echo '</fieldset></td></tr><tr class="gfbrc-method-row" data-gfbrc-for="composite"><th scope="row"><label for="gfbrc-separator">' .
            esc_html__('Composite separator', 'gf-bulk-rename-counter') . '</label></th><td>';
        echo '<input id="gfbrc-separator" type="text" maxlength="32" name="' . esc_attr($prefix) .
            '[_separator]" value="' . esc_attr((string)$sep) . '">';
        echo '<p class="description">' .
            esc_html__('Avoid personal data in selected fields; missing or unsafe values cause a visible ZIP preparation error.', 'gf-bulk-rename-counter') .
            '</p></td></tr></tbody></table>';
        submit_button(__('Save this form rule', 'gf-bulk-rename-counter'));
        echo '</form></section>';
    }

    /** Scoped presentation only. The Settings API always receives the same inputs. */
    private static function render_presentation(): void {
        ?>
<style id="gfbrc-settings-presentation">
.gfbrc-settings{max-width:1030px}
.gfbrc-settings *{box-sizing:border-box}
.gfbrc-settings>h1{margin-bottom:12px}
.gfbrc-settings-intro{max-width:76ch;margin:0 0 20px;color:#50575e;line-height:1.8}
.gfbrc-settings-intro p{margin:0 0 8px}
.gfbrc-settings-card{background:#fff;border:1px solid #c3c4c7;padding:18px 24px 20px;margin:0 0 18px;min-width:0;overflow-wrap:anywhere}
.gfbrc-settings-card h2{font-size:17px;line-height:1.5;margin:0 0 10px;font-weight:600}
.gfbrc-settings-card>p,.gfbrc-settings-card .description{max-width:76ch;color:#50575e;line-height:1.75}
.gfbrc-settings-card form{margin-top:14px}
.gfbrc-settings-card .form-table{margin:6px 0 0;table-layout:fixed}
.gfbrc-settings-card .form-table th{width:185px;line-height:1.65;padding:16px 0 14px 18px;text-align:start}
.gfbrc-settings-card .form-table td{padding:14px 0;line-height:1.65;vertical-align:top}
.gfbrc-settings-card .form-table td>.description{margin:8px 0 0}
.gfbrc-settings-card .form-table select{max-width:100%;width:min(370px,100%);min-height:32px}
.gfbrc-settings-card .form-table input.small-text{min-height:32px}
.gfbrc-settings-card .form-table details{max-width:100%;margin:10px 0 0}
.gfbrc-settings-card .form-table details summary{cursor:pointer}
.gfbrc-settings-card .submit{margin:16px 0 0;padding:0;text-align:start}
.gfbrc-settings-card .submit .button{min-height:34px}
.gfbrc-settings-per-form>form:first-of-type{display:flex;align-items:center;flex-wrap:wrap;gap:10px 12px}
.gfbrc-settings-per-form>form:first-of-type .submit{margin:0}
.gfbrc-settings-per-form>form:first-of-type select{width:min(330px,100%);max-width:100%}
.gfbrc-settings-card .gfbrc-rule-status{margin:16px 0;padding:8px 12px}
.gfbrc-settings-card .gfbrc-rule-status p{margin:6px 0;line-height:1.75}
.gfbrc-settings-card .gfbrc-method-row[hidden]{display:none!important}
.gfbrc-settings-card .gfbrc-composite-row{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(70px,90px);gap:10px;align-items:center;padding:10px 12px;margin:8px 0;border:1px solid #dcdcde;border-radius:3px}
.gfbrc-settings-card .gfbrc-composite-row label:first-child{display:flex;gap:8px;align-items:center;min-width:0}
.gfbrc-settings-card .gfbrc-composite-row input[type=checkbox]{margin:0;flex-shrink:0}
.gfbrc-settings-card .gfbrc-composite-row input[type=number]{width:100%;max-width:90px;min-width:0}
.gfbrc-settings-card bdi{unicode-bidi:isolate}
.gfbrc-settings-card input:focus-visible,.gfbrc-settings-card select:focus-visible,.gfbrc-settings-card summary:focus-visible{outline:2px solid #2271b1;outline-offset:2px}
@media(max-width:782px){.gfbrc-settings{max-width:100%}.gfbrc-settings-card{padding:14px 12px}.gfbrc-settings-card .form-table{table-layout:auto}.gfbrc-settings-card .form-table th{width:auto;padding:12px 0 4px}.gfbrc-settings-card .form-table td{padding:6px 0 12px}.gfbrc-settings-card .form-table select{width:100%}.gfbrc-settings-card .gfbrc-composite-row{grid-template-columns:minmax(0,1fr) auto minmax(60px,74px);gap:6px;padding:8px}}
</style>
<script id="gfbrc-settings-method-visibility">
(function(){
  var method = document.getElementById('gfbrc-method');
  if (!method) return;
  var rows = document.querySelectorAll('.gfbrc-settings-per-form .gfbrc-method-row');
  function update() {
    rows.forEach(function(row) {
      row.hidden = row.getAttribute('data-gfbrc-for') !== method.value;
      /* Do NOT disable hidden controls: switching methods and Settings API
         submission preserve the exact existing server-side sanitizer contract. */
    });
  }
  method.addEventListener('change', update);
  update();
})();
</script>
        <?php
    }
}
