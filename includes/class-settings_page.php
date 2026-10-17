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
        <p class="description"><?php esc_html_e( 'Enter the ID of the field that stores the unique counter number (e.g., 5).', 'gf-bulk-rename-counter' ); ?></p>
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
            <form action="options.php" method="post">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( 'gf-bulk-rename-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
