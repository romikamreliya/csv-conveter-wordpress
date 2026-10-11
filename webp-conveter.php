<?php
/**
 * Plugin Name:       WebP Converter & Compressor
 * Description:       Converts JPG/PNG images to WebP, compresses them while keeping good clarity, and replaces the live image paths everywhere (posts, products, Elementor, options). Supports bulk conversion, single WooCommerce product conversion, auto-convert on upload and one-click restore.
 * Version:           1.1.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            AppWorld
 * Text Domain:       webp-conveter
 * WC requires at least: 5.0
 * WC tested up to:   11.2
 */

defined( 'ABSPATH' ) || exit;

define( 'WEBPC_VERSION', '1.1.1' );
define( 'WEBPC_FILE', __FILE__ );
define( 'WEBPC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WEBPC_URL', plugin_dir_url( __FILE__ ) );

require_once WEBPC_DIR . 'includes/class-webpc-settings.php';
require_once WEBPC_DIR . 'includes/class-webpc-converter.php';
require_once WEBPC_DIR . 'includes/class-webpc-replacer.php';
require_once WEBPC_DIR . 'includes/class-webpc-attachment.php';
require_once WEBPC_DIR . 'includes/class-webpc-admin.php';

// Declare WooCommerce HPOS compatibility (we only touch posts/attachments).
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WEBPC_FILE, true );
	}
} );

add_action( 'plugins_loaded', function () {
	WebPC_Attachment::init();
	if ( is_admin() ) {
		WebPC_Admin::init();
	}
} );

/**
 * Older Apache builds don't know the .webp MIME type and send no Content-Type. Combined with the
 * common "X-Content-Type-Options: nosniff" security header, browsers would refuse to show the image.
 * Add the type in uploads/.htaccess (ignored by nginx, harmless everywhere else).
 */
function webpc_htaccess( $add = true ) {
	$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) $_SERVER['SERVER_SOFTWARE'] ) : ''; // phpcs:ignore
	if ( false === strpos( $server, 'apache' ) && false === strpos( $server, 'litespeed' ) ) {
		return;
	}
	if ( ! function_exists( 'insert_with_markers' ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}
	// AddType needs "AllowOverride FileInfo" – the same thing WordPress' own RewriteRules need.
	// Only write it when those rules are proven to work, so we can never cause a 500 error.
	$root = ABSPATH . '.htaccess';
	if ( $add && ( ! get_option( 'permalink_structure' ) || ! file_exists( $root ) || false === stripos( (string) file_get_contents( $root ), 'RewriteRule' ) ) ) {
		return;
	}
	$file = trailingslashit( wp_get_upload_dir()['basedir'] ) . '.htaccess';
	if ( ( file_exists( $file ) && ! wp_is_writable( $file ) ) || ( ! file_exists( $file ) && ! wp_is_writable( dirname( $file ) ) ) ) {
		return;
	}
	insert_with_markers( $file, 'WebP Converter', $add ? array(
		'<IfModule mod_mime.c>',
		'AddType image/webp .webp',
		'</IfModule>',
	) : array() );
}

register_activation_hook( __FILE__, function () {
	if ( false === get_option( WebPC_Settings::OPTION ) ) {
		add_option( WebPC_Settings::OPTION, WebPC_Settings::defaults() );
	}
	webpc_htaccess( true );
} );

register_deactivation_hook( __FILE__, function () {
	// Keep the MIME type if converted images exist – they still need it after deactivation.
	global $wpdb;
	if ( ! $wpdb->get_var( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_webpc_stats' LIMIT 1" ) ) {
		webpc_htaccess( false );
	}
} );

// Sites that updated the plugin without re-activating.
add_action( 'admin_init', function () {
	if ( get_option( 'webpc_htaccess_v' ) !== WEBPC_VERSION ) {
		webpc_htaccess( true );
		update_option( 'webpc_htaccess_v', WEBPC_VERSION, false );
	}
} );
