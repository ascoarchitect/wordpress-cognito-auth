<?php
/**
 * Plugin Name: WordPress Cognito Authentication & Sync
 * Plugin URI: https://github.com/yourrepo/wp-cognito-auth
 * Description: Complete WordPress integration with Amazon Cognito - handles authentication via JWT/OIDC and bidirectional user synchronization.
 * Version: 2.2.0
 * Author: Adam Scott
 * Text Domain: wp-cognito-auth
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * License: GPL v3 or later
 * Network: false
 *
 * @package WP_Cognito_Auth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'WP_COGNITO_AUTH_VERSION', '2.2.0' );
define( 'WP_COGNITO_AUTH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_COGNITO_AUTH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_COGNITO_AUTH_PLUGIN_FILE', __FILE__ );

// Autoload classes.
spl_autoload_register(
	function ( $class_name ) {
		$prefix   = 'WP_Cognito_Auth\\';
		$base_dir = WP_COGNITO_AUTH_PLUGIN_DIR . 'includes/';

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class_name, $len );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

/**
 * Initialize the plugin immediately - don't wait for hooks.
 */
function wp_cognito_auth_init() {
	try {
		$plugin = new WP_Cognito_Auth\Plugin();
		$plugin->init();
	} catch ( Exception $e ) {
		// Silently handle initialization errors.
		error_log( 'WP Cognito Auth initialization failed: ' . $e->getMessage() );
	}
}

// Initialize immediately when plugin loads.
wp_cognito_auth_init();

// Activation hook.
register_activation_hook( __FILE__, 'wp_cognito_auth_activate' );

/**
 * Activation function - set up default options.
 */
function wp_cognito_auth_activate() {
	// Authentication settings.
	add_option( 'wp_cognito_auth_enabled', false );
	add_option( 'wp_cognito_auth_user_pool_id', '' );
	add_option( 'wp_cognito_auth_client_id', '' );
	add_option( 'wp_cognito_auth_client_secret', '' );
	add_option( 'wp_cognito_auth_region', 'us-east-1' );
	add_option( 'wp_cognito_auth_hosted_ui_domain', '' );
	add_option( 'wp_cognito_auth_auto_create_users', true );
	add_option( 'wp_cognito_auth_default_role', 'subscriber' );
	add_option( 'wp_cognito_auth_force_cognito', false );

	// Button customization settings.
	add_option( 'wp_cognito_auth_button_bg_color', '#ff9900' );
	add_option( 'wp_cognito_auth_button_text_color', '#ffffff' );
	add_option( 'wp_cognito_auth_button_text', 'Login with Cognito' );

	// Sync settings.
	add_option( 'wp_cognito_sync_enabled', false );
	add_option( 'wp_cognito_sync_api_url', '' );
	add_option( 'wp_cognito_sync_api_key', '' );
	add_option( 'wp_cognito_sync_on_login', false );
	add_option( 'wp_cognito_sync_groups', array() );
	add_option( 'wp_cognito_sync_logs', array() );

	// Feature toggles.
	add_option(
		'wp_cognito_features',
		array(
			'authentication' => false,
			'sync'           => false,
			'group_sync'     => false,
		)
	);

	// Generate emergency access parameter if it doesn't exist.
	if ( ! get_option( 'wp_cognito_emergency_access_param' ) ) {
		$emergency_param = wp_generate_password( 32, false, false );
		add_option( 'wp_cognito_emergency_access_param', $emergency_param );
	}

	// Flush rewrite rules.
	flush_rewrite_rules();
}

// Deactivation hook.
register_deactivation_hook( __FILE__, 'wp_cognito_auth_deactivate' );

/**
 * Deactivation function - clean up temporary data.
 */
function wp_cognito_auth_deactivate() {
	flush_rewrite_rules();
}

// Uninstall hook.
register_uninstall_hook( __FILE__, 'wp_cognito_auth_uninstall' );

/**
 * Uninstall function - clean up all plugin data.
 */
function wp_cognito_auth_uninstall() {
	// Remove all plugin options.
	$options = array(
		'wp_cognito_auth_enabled',
		'wp_cognito_auth_user_pool_id',
		'wp_cognito_auth_client_id',
		'wp_cognito_auth_client_secret',
		'wp_cognito_auth_region',
		'wp_cognito_auth_hosted_ui_domain',
		'wp_cognito_auth_auto_create_users',
		'wp_cognito_auth_default_role',
		'wp_cognito_auth_force_cognito',
		'wp_cognito_auth_button_bg_color',
		'wp_cognito_auth_button_text_color',
		'wp_cognito_auth_button_text',
		'wp_cognito_sync_enabled',
		'wp_cognito_sync_api_url',
		'wp_cognito_sync_api_key',
		'wp_cognito_sync_on_login',
		'wp_cognito_sync_groups',
		'wp_cognito_sync_logs',
		'wp_cognito_features',
		'wp_cognito_emergency_access_param',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Clean up user meta.
	global $wpdb;
	$wpdb->delete(
		$wpdb->usermeta,
		array( 'meta_key' => 'cognito_user_id' ),
		array( '%s' )
	);

	// Clean up post meta for content restrictions.
	$wpdb->delete(
		$wpdb->postmeta,
		array( 'meta_key' => '_cognito_logged_in_only' ),
		array( '%s' )
	);

	// Clean up transients.
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_wp_cognito_%'
         OR option_name LIKE '_transient_timeout_wp_cognito_%'"
	);
}
