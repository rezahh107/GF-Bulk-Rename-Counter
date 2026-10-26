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
        echo '<p>' . esc_html__( 'Configure which form and which field holds the unique counter number. The downloaded files will be renamed to d[COUNTER].extension', 'gf-bulk-rename-counter' ) . '</p>';
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
        <div class="wrap">
            <h1><?php echo esc_html__( 'Gravity Forms Bulk Rename Settings', 'gf-bulk-rename-counter' ); ?></h1>
            <?php Admin_Experience::settings_intro(); ?>
            <?php settings_errors( Form_Naming_Rules::OPTION_NAME ); ?>
            <form action="options.php" method="post">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( 'gf-bulk-rename-settings' );
                submit_button();
                ?>
            </form>
            <?php self::render_per_form_rules(); ?>
        </div>
        <?php
    }
    /** A second native Settings API form edits only the selected Gravity Form. */
    private static function render_per_form_rules(): void {
        echo '<hr><h2>' . esc_html__('Per-form naming (independent flat ZIP only)', 'gf-bulk-rename-counter') . '</h2>';
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
            echo '<p class="description">' . esc_html__('Choose a form to see its effective download naming rule and edit an optional independent override.', 'gf-bulk-rename-counter') . '</p>';
            return;
        }
        $form = \GFAPI::get_form($selected);
        if (!is_array($form)) {
            echo '<p>' . esc_html__('Form unavailable.', 'gf-bulk-rename-counter') . '</p>';
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
        echo '<tr><th scope="row"><label for="gfbrc-single">' .
            esc_html__('Single Field source', 'gf-bulk-rename-counter') . '</label></th><td>';
        echo '<select id="gfbrc-single" name="' . esc_attr($prefix) . '[_single_field_id]">';
        echo '<option value="0">' . esc_html__('— Choose a text or number field —', 'gf-bulk-rename-counter') . '</option>';
        foreach ($fields as $id => $label) {
            echo '<option value="' . esc_attr((string)$id) . '" ' . selected($single, $id, false) . '>' .
                esc_html($label . ' (ID: ' . $id . ')') . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Composite fields and order', 'gf-bulk-rename-counter') .
            '</th><td><fieldset><legend class="screen-reader-text">' .
            esc_html__('Composite fields and order', 'gf-bulk-rename-counter') . '</legend>';
        echo '<p class="description">' .
            esc_html__('For Composite, check at least two eligible text/number fields and assign consecutive positions 1, 2, 3 … . Empty Entry values will block ZIP creation.', 'gf-bulk-rename-counter') . '</p>';
        foreach ($fields as $id => $label) {
            $rank = isset($ranks[$id]) ? $ranks[$id] + 1 : '';
            echo '<p><label><input type="checkbox" name="' . esc_attr($prefix) . '[_include][' . esc_attr((string)$id) .
                ']" value="1" ' . checked(isset($ranks[$id]), true, false) . '> ' .
                esc_html($label . ' (ID: ' . $id . ')') . '</label> ';
            echo '<label for="gfbrc-order-' . esc_attr((string)$id) . '">' .
                esc_html__('Position', 'gf-bulk-rename-counter') . '</label> ';
            echo '<input id="gfbrc-order-' . esc_attr((string)$id) . '" type="number" min="1" step="1" class="small-text" name="' .
                esc_attr($prefix) . '[_field_order][' . esc_attr((string)$id) . ']" value="' .
                esc_attr((string)$rank) . '"></p>';
        }
        echo '</fieldset></td></tr><tr><th scope="row"><label for="gfbrc-separator">' .
            esc_html__('Composite separator', 'gf-bulk-rename-counter') . '</label></th><td>';
        echo '<input id="gfbrc-separator" type="text" maxlength="32" name="' . esc_attr($prefix) .
            '[_separator]" value="' . esc_attr((string)$sep) . '">';
        echo '<p class="description">' .
            esc_html__('Avoid personal data in selected fields; missing or unsafe values cause a visible ZIP preparation error.', 'gf-bulk-rename-counter') .
            '</p></td></tr></tbody></table>';
        submit_button(__('Save this form rule', 'gf-bulk-rename-counter'));
        echo '</form>';
    }
}
