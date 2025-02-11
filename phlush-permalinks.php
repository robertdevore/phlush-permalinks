<?php
/**
  * The plugin bootstrap file
  * 
  * @link              https://robertdevore.com
  * @since             1.0.0
  * @package           Phlush_Permalinks
  *
  * @wordpress-plugin
  * Plugin Name:          Phlush Permalinks
  * Plugin URI:           https://robertdevore.com/phlush-permalinks-wordpress-plugin/
  * Description:          Automatic permalink flushing at custom intervals and triggers flush on selected actions for enhanced site management.
  * Version:              1.0.0
  * Author:               Robert DeVore
  * Author URI:           https://robertdevore.com
  * License:              GPLv3
  * License URI:          http://www.gnu.org/licenses/gpl-3.0.txt
  * Text Domain:          phlush-permalinks
  * Domain Path:          /languages
  */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    exit;
}

require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/robertdevore/phlush-permalinks/',
	__FILE__,
	'phlush-permalinks'
);

//Set the branch that contains the stable release.
$myUpdateChecker->setBranch( 'main' );

// Check if Composer's autoloader is already registered globally.
if ( ! class_exists( 'RobertDevore\WPComCheck\WPComPluginHandler' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use RobertDevore\WPComCheck\WPComPluginHandler;

new WPComPluginHandler( plugin_basename( __FILE__ ), 'https://robertdevore.com/why-this-plugin-doesnt-support-wordpress-com-hosting/' );

// Plugin constants.
define( 'PHLUSH_PERMALINKS__VERSION', '1.0.0' );
define( 'PHLUSH_PERMALINKS_PLUGIN_SLUG', 'phlush_permalinks_plugin' );
define( 'PHLUSH_PERMALINKS_OPTION_NAME', 'phlush_permalinks_flush_interval' );
define( 'PHLUSH_PERMALINKS_ACTIONS_OPTION_NAME', 'phlush_permalinks_flush_actions' );

/**
 * Activation hook: Schedules the permalink flush event on plugin activation.
 * 
 * @since  1.0.0
 */
function phlush_permalinks_schedule_permalink_flush() {
    // Ensure custom cron interval is added before scheduling the event
    add_filter( 'cron_schedules', 'phlush_permalinks_add_custom_cron_interval' );

    $interval = get_option( PHLUSH_PERMALINKS_OPTION_NAME, 5 ); // Default to 5 minutes
    if ( ! wp_next_scheduled( 'phlush_permalinks_flush_permalinks' ) ) {
        wp_schedule_event( time(), 'phlush_permalinks_custom_interval', 'phlush_permalinks_flush_permalinks' );
    }
}
register_activation_hook( __FILE__, 'phlush_permalinks_schedule_permalink_flush' );

/**
 * Deactivation hook: Clears the scheduled event on plugin deactivation.
 * 
 * @since  1.0.0
 */
function phlush_permalinks_clear_scheduled_event() {
    wp_clear_scheduled_hook( 'phlush_permalinks_flush_permalinks' );
}
register_deactivation_hook( __FILE__, 'phlush_permalinks_clear_scheduled_event' );

/**
 * Adds a custom cron interval based on the user-defined setting.
 * 
 * @since  1.0.0
 */
function phlush_permalinks_add_custom_cron_interval( $schedules ) {
    $interval = absint( get_option( PHLUSH_PERMALINKS_OPTION_NAME, 5 ) );

    // Create custom interval.
    $schedules['phlush_permalinks_custom_interval'] = [
        'interval' => $interval * 60, // Convert minutes to seconds
        'display'  => sprintf( esc_html__( 'Custom Interval (%d minutes)', 'phlush-permalinks' ), $interval ),
    ];
    return $schedules;
}
add_filter( 'cron_schedules', 'phlush_permalinks_add_custom_cron_interval' );

/**
 * Function to flush permalinks and log the action.
 * 
 * @since 1.0.0
 */
function phlush_permalinks_flush_permalinks_function() {
    // Only flush once per request
    if ( defined( 'PHLUSH_PERMALINKS_FLUSHED' ) ) {
        return; // Exit if already flushed during this request
    }

    global $post;

    // Ensure a valid post object is provided before proceeding
    if ( ! is_object( $post ) || ! isset( $post->post_type ) ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            //error_log( "Phlush Permalinks: Flush function triggered without a valid post object on " . current_time( 'mysql' ) );
        }
        //return; // Exit if no valid post object
    }

    // Set the flag to indicate permalinks have been flushed
    define( 'PHLUSH_PERMALINKS_FLUSHED', true );

    // Log a message to debug.log to check if the function is being triggered.
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        $post_type = get_post_type( $post );
        if ( $post_type ) {
            error_log( "Phlush Permalinks: Flush function triggered for post type '{$post_type}' on " . current_time( 'mysql' ) );
        }

        error_log( 'Phlush Permalinks: Specific action hook triggered on ' . current_time( 'mysql' ) );
    }

    // Flush the rewrite rules.
    flush_rewrite_rules( true );
    
    // Log a message to debug.log to indicate that permalinks were flushed.
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'Phlush Permalinks: Permalinks were flushed successfully on ' . current_time( 'mysql' ) );
    }
}
add_action( 'phlush_permalinks_flush_permalinks', 'phlush_permalinks_flush_permalinks_function', 10 );

/**
 * Adds the settings page under the WordPress 'Settings' menu.
 */
function phlush_permalinks_add_settings_page() {
    add_options_page(
        esc_html__( 'Phlush Permalinks Settings', 'phlush-permalinks' ),
        esc_html__( 'Phlush Permalinks', 'phlush-permalinks' ),
        'manage_options',
        PHLUSH_PERMALINKS_PLUGIN_SLUG,
        'phlush_permalinks_render_settings_page'
    );
}
add_action( 'admin_menu', 'phlush_permalinks_add_settings_page' );

/**
 * Enqueues the Select2 library and custom scripts/styles for the settings page.
 * 
 * @since 1.0.0
 */
function phlush_permalinks_enqueue_admin_scripts( $hook ) {
    // Check if the current page is the Phlush Permalinks settings page.
    if ( $hook !== 'settings_page_' . PHLUSH_PERMALINKS_PLUGIN_SLUG ) {
        return; // Exit if not on the correct settings page.
    }

    // Define the path to the assets folder.
    $plugin_url = plugin_dir_url( __FILE__ );

    // Enqueue Select2 CSS and JS from the assets folder.
    wp_enqueue_style( 'select2-css', $plugin_url . 'assets/css/select2.min.css', [], '4.1.0' );
    wp_enqueue_script( 'select2-js', $plugin_url . 'assets/js/select2.min.js', [ 'jquery' ], '4.1.0', true );

    // Enqueue custom script for handling Select2 initialization.
    wp_add_inline_script( 'select2-js', 'jQuery(document).ready(function($) { $(".phlush-permalinks-select2").select2(); });' );
}
add_action( 'admin_enqueue_scripts', 'phlush_permalinks_enqueue_admin_scripts' );

/**
 * Renders the settings page where the user can set the flush interval and select actions to trigger the flush.
 * 
 * @since  1.0.0
 */
function phlush_permalinks_render_settings_page() {
    ?>
    <div id="phlush-permalinks" class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <p><?php esc_attr_e( 'Brought to you by', 'phlush-permalinks' ); ?> <a href="https://robertdevore.com/" target="_blank"><?php esc_attr_e( 'Robert DeVore', 'phlush-permalinks' ) ?></a> | <a href="https://robertdevore.com/contact">Get Support</a></p>
        <hr />
        <form method="post" action="options.php">
            <?php
            settings_fields( PHLUSH_PERMALINKS_PLUGIN_SLUG );
            wp_nonce_field( 'phlush_permalinks_save_settings', 'phlush_permalinks_nonce' );
            do_settings_sections( PHLUSH_PERMALINKS_PLUGIN_SLUG );
            submit_button( esc_html__( 'Save Settings', 'phlush-permalinks' ) );
            ?>
        </form>
    </div>
    <?php
}

/**
 * Registers the plugin settings with WordPress.
 * 
 * @since  1.0.0
 */
function phlush_permalinks_register_settings() {
    register_setting( PHLUSH_PERMALINKS_PLUGIN_SLUG, PHLUSH_PERMALINKS_OPTION_NAME, [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 5,
    ]);

    register_setting( PHLUSH_PERMALINKS_PLUGIN_SLUG, PHLUSH_PERMALINKS_ACTIONS_OPTION_NAME, [
        'type'              => 'array',
        'sanitize_callback' => 'phlush_permalinks_sanitize_actions',
        'default'           => array_keys( phlush_permalinks_get_available_actions() ),
    ] );

    add_settings_section(
        'phlush_permalinks_settings_section',
        esc_html__( 'Phlush Permalinks Settings', 'phlush-permalinks' ),
        null,
        PHLUSH_PERMALINKS_PLUGIN_SLUG
    );

    add_settings_field(
        'phlush_permalinks_flush_interval',
        esc_html__( 'Flush Interval (minutes)', 'phlush-permalinks' ),
        'phlush_permalinks_render_flush_interval_field',
        PHLUSH_PERMALINKS_PLUGIN_SLUG,
        'phlush_permalinks_settings_section'
    );

    add_settings_field(
        'phlush_permalinks_flush_actions',
        esc_html__( 'Flush Actions', 'phlush-permalinks' ),
        'phlush_permalinks_render_flush_actions_field',
        PHLUSH_PERMALINKS_PLUGIN_SLUG,
        'phlush_permalinks_settings_section'
    );
}
add_action( 'admin_init', 'phlush_permalinks_register_settings' );

/**
 * Renders the input field for setting the flush interval.
 * 
 * @since  1.0.0
 */
function phlush_permalinks_render_flush_interval_field() {
    $interval = absint( get_option( PHLUSH_PERMALINKS_OPTION_NAME, 5 ) );
    echo '<input type="number" name="' . esc_attr( PHLUSH_PERMALINKS_OPTION_NAME ) . '" value="' . esc_attr( $interval ) . '" min="1" />';
}

/**
 * Renders the Select2 multi-select field for choosing which actions should trigger a permalink flush.
 * 
 * @since 1.0.0
 */
function phlush_permalinks_render_flush_actions_field() {
    // Retrieve the saved actions, or use the full list of available actions as the default if nothing is saved.
    $saved_actions = get_option( PHLUSH_PERMALINKS_ACTIONS_OPTION_NAME, array_keys( phlush_permalinks_get_available_actions() ) );

    $available_actions = phlush_permalinks_get_available_actions();
    
    echo '<select name="' . esc_attr( PHLUSH_PERMALINKS_ACTIONS_OPTION_NAME ) . '[]" class="phlush-permalinks-select2" multiple="multiple" style="width: 100%;">';

    // Loop through the available actions.
    foreach ( $available_actions as $action => $label ) {
        // Automatically select all actions by default if no specific selections are saved.
        $selected = in_array( $action, $saved_actions, true ) ? 'selected="selected"' : '';
        echo '<option value="' . esc_attr( $action ) . '" ' . esc_attr( $selected ) . '>' . esc_html( $label ) . '</option>';
    }
    
    echo '</select>';
}

/**
 * Updates the cron schedule when the flush interval setting is changed.
 * 
 * @since  1.0.0
 */
function phlush_permalinks_update_cron_schedule( $old_value, $new_value ) {
    phlush_permalinks_clear_scheduled_event();
    phlush_permalinks_schedule_permalink_flush();
}
add_action( 'update_option_' . PHLUSH_PERMALINKS_OPTION_NAME, 'phlush_permalinks_update_cron_schedule', 10, 2 );

/**
 * Retrieves the available actions that can trigger a permalink flush.
 *
 * @return array List of action hooks and their labels.
 * 
 * @since  1.0.0
 */
function phlush_permalinks_get_available_actions() {
    $actions = [
        'save_post'                      => esc_attr__( 'Post Save/Edit', 'phlush-permalinks' ),
        'wp_insert_post'                 => esc_attr__( 'Post Creation', 'phlush-permalinks' ),
        'edit_post'                      => esc_attr__( 'Post Edit', 'phlush-permalinks' ),
        'delete_post'                    => esc_attr__( 'Post Deletion', 'phlush-permalinks' ),
        'save_page'                      => esc_attr__( 'Page Save/Edit', 'phlush-permalinks' ),
        'wp_insert_page'                 => esc_attr__( 'Page Creation', 'phlush-permalinks' ),
        'create_category'                => esc_attr__( 'Category Creation', 'phlush-permalinks' ),
        'edit_category'                  => esc_attr__( 'Category Edit', 'phlush-permalinks' ),
        'delete_category'                => esc_attr__( 'Category Deletion', 'phlush-permalinks' ),
        'create_term'                    => esc_attr__( 'Term Creation', 'phlush-permalinks' ),
        'edit_term'                      => esc_attr__( 'Term Edit', 'phlush-permalinks' ),
        'delete_term'                    => esc_attr__( 'Term Deletion', 'phlush-permalinks' ),
        'update_option_nav_menu'         => esc_attr__( 'Menu Update', 'phlush-permalinks' ),
        'update_option_sidebars_widgets' => esc_attr__( 'Widgets Update', 'phlush-permalinks' ),
    ];

    if ( class_exists( 'WooCommerce' ) ) {
        $actions['save_post_product']             = esc_attr__( 'WooCommerce Product Save/Edit', 'phlush-permalinks' );
        $actions['woocommerce_product_set_stock'] = esc_attr__( 'WooCommerce Product Stock Update', 'phlush-permalinks' );
    }

    if ( defined( 'WPSEO_VERSION' ) ) {
        $actions['wpseo_save_post'] = esc_attr__( 'Yoast SEO Post Save', 'phlush-permalinks' );
    }

    if ( function_exists( 'acf' ) ) {
        $actions['acf/save_post'] = esc_attr__( 'ACF Field Group Save', 'phlush-permalinks' );
    }

    return apply_filters( 'phlush_permalinks_available_actions', $actions );
}

/**
 * Hooks into the selected actions and flushes permalinks when those actions occur.
 * 
 * @since 1.0.0
 */
function phlush_permalinks_hook_into_selected_actions() {
    // Retrieve and sanitize the selected actions from the options.
    $actions = phlush_permalinks_sanitize_actions( get_option( PHLUSH_PERMALINKS_ACTIONS_OPTION_NAME, array_keys( phlush_permalinks_get_available_actions() ) ) );

    // Loop through each action and hook the flush function to it.
    foreach ( $actions as $action ) {
        add_action( $action, 'phlush_permalinks_flush_permalinks_function' );
    }

    // Hook into the REST API saves for Gutenberg compatibility
    add_action( 'rest_after_insert_post', 'phlush_permalinks_flush_permalinks_function', 10, 3 );
    add_action( 'rest_after_insert_page', 'phlush_permalinks_flush_permalinks_function', 10, 3 );

    // Dynamically hook into REST API saves for all custom post types
    $custom_post_types = get_post_types( [ 'public' => true, '_builtin' => false ] );
    // Loop through all custom post types.
    foreach ( $custom_post_types as $post_type ) {
        add_action( "rest_after_insert_{$post_type}", 'phlush_permalinks_flush_permalinks_function', 10, 3 );
    }
}
add_action( 'init', 'phlush_permalinks_hook_into_selected_actions' );

/**
 * Sanitize the selected actions for flushing permalinks.
 *
 * @param array $actions The array of selected actions.
 * @return array Sanitized array of valid actions.
 * 
 * @since 1.0.0
 */
function phlush_permalinks_sanitize_actions( $actions ) {
    if ( ! is_array( $actions ) ) {
        return [];
    }

    $available_actions = phlush_permalinks_get_available_actions();

    // Filter the actions to ensure they are valid
    return array_filter( $actions, function( $action ) use ( $available_actions ) {
        return isset( $available_actions[ $action ] );
    });
}
