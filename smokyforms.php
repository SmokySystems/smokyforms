<?php
/**
 * Plugin Name:       SmokyForms – Drag and Drop Form Builder with Calculations
 * Plugin URI:        https://www.smokyforms.com
 * Description:       Drag and drop form builder with calculations included for free. Build contact forms, quote and order forms and calculators without writing formulas.
 * Version:           0.2.4
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            SmokySystems
 * Author URI:        https://www.smokysystems.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smokyforms
 * Domain Path:       /languages
 *
 * @package SmokyForms
 */

defined( 'ABSPATH' ) || exit;

define( 'SMOKYFORMS_VERSION', '0.2.4' );
define( 'SMOKYFORMS_FILE', __FILE__ );
define( 'SMOKYFORMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMOKYFORMS_URL', plugin_dir_url( __FILE__ ) );

// Some sites strip the ?ver= parameter from every script and style address, so browsers keep an old copy of the files
// after an update. A parameter of our own survives that and makes the address change with each version.
add_filter(
	'script_loader_src',
	static function ( $src ) {
		return is_string( $src ) && 0 === strpos( $src, SMOKYFORMS_URL ) && false === strpos( $src, 'sfv=' ) ? add_query_arg( 'sfv', SMOKYFORMS_VERSION, $src ) : $src;
	},
	PHP_INT_MAX
);
add_filter(
	'style_loader_src',
	static function ( $src ) {
		return is_string( $src ) && 0 === strpos( $src, SMOKYFORMS_URL ) && false === strpos( $src, 'sfv=' ) ? add_query_arg( 'sfv', SMOKYFORMS_VERSION, $src ) : $src;
	},
	PHP_INT_MAX
);

// Shared SMTP module of the SmokySystems plugins. Every plugin ships a copy; only the newest copy runs.
require_once SMOKYFORMS_DIR . 'lib/smokysystems-mail/loader.php';

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'SmokySystems\\SmokyForms\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = SMOKYFORMS_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'SmokySystems\\SmokyForms\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SmokySystems\\SmokyForms\\Plugin', 'deactivate' ) );

SmokySystems\SmokyForms\Plugin::instance();
