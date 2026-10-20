<?php
/**
 * Plugin Name:     Gravity Forms Bulk Rename Counter
 * Plugin URI:      https://github.com/rezahh107/GF-Bulk-Rename-Counter
 * Description:     Preserves legacy counter filenames and adds an independent verified flat ZIP download with per-form naming rules.
 * Version:         2.1.0
 * Author:          Reza Hashemi Hosseini
 * Text Domain:     gf-bulk-rename-counter
 * Domain Path:     /languages
 * License:         GPL-2.0-or-later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP:    8.0
 * Requires WP:     5.8
 *
 * @package         GFBulkRename
 */

namespace GFBulkRename;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define constants
define( 'GFBULKRENAME_VERSION', '2.1.0' );
define( 'GFBULKRENAME_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFBULKRENAME_INCLUDES_DIR', GFBULKRENAME_PLUGIN_DIR . 'includes/' );

// Autoloader - maps class names to files like class-settings_page.php
spl_autoload_register( function ( string $class ): void {
    $prefix = __NAMESPACE__ . '\\';
    $base_dir = GFBULKRENAME_INCLUDES_DIR;
    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }
    $relative_class = substr( $class, $len );
    $file_name = strtolower( $relative_class );
    $file_name = str_replace( '\\', '-', $file_name );
    $file = $base_dir . 'class-' . $file_name . '.php';
    if ( file_exists( $file ) ) {
        require $file;
    }
} );

/**
 * Main plugin class (Singleton)
 *
 * @since 2.0.0
 */
final class Plugin {

    private static ?self $instance = null;

    private function __construct() {
        $this->init_hooks();
    }

    public static function get_instance(): self {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function init_hooks(): void {
        add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
        add_action( 'plugins_loaded', [ $this, 'register_filters' ], 20 );
        add_action( 'admin_init', [ Settings_Page::class, 'register_settings' ] );
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
    }

    public function activate(): void {
        if ( function_exists( 'opcache_reset' ) ) {
            opcache_reset();
        }
    }

    public function load_textdomain(): void {
        load_plugin_textdomain(
            'gf-bulk-rename-counter',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }

    public function register_filters(): void {
        if ( ! class_exists( 'GFAPI' ) ) {
            add_action( 'admin_notices', [ $this, 'missing_gf_notice' ] );
            return;
        }

        if ( ! defined( 'BDFGF_VERSION' ) ) {
            add_action( 'admin_notices', [ $this, 'missing_bulk_download_notice' ] );
            return;
        }

        $handler = new Rename_Handler();
        add_filter( 'bdfgf_entry_filename', [ $handler, 'rename_by_counter' ], 10, 3 );
        Flat_Zip_Download::register();
    }

    public function missing_gf_notice(): void {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php esc_html_e( 'Gravity Forms Bulk Rename Counter requires Gravity Forms to be installed and active.', 'gf-bulk-rename-counter' ); ?></p>
        </div>
        <?php
    }

    public function missing_bulk_download_notice(): void {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php esc_html_e( 'Gravity Forms Bulk Rename Counter requires "Bulk Download for Gravity Forms" plugin to be installed and active.', 'gf-bulk-rename-counter' ); ?></p>
        </div>
        <?php
    }

    public function register_admin_menu(): void {
        add_options_page(
            __( 'Gravity Forms Bulk Rename Settings', 'gf-bulk-rename-counter' ),
            __( 'GF Bulk Rename', 'gf-bulk-rename-counter' ),
            'manage_options',
            'gf-bulk-rename-settings',
            [ Settings_Page::class, 'render' ]
        );
    }
}

Plugin::get_instance();
