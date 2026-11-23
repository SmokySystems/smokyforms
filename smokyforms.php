<?php
/**
 * Plugin Name:       SmokyForms – Drag and Drop Form Builder with Calculations
 * Plugin URI:        https://www.smokyforms.com
 * Description:       Drag and drop form builder with calculations included for free. Build contact forms, quote and order forms and calculators without writing formulas.
 * Version:           0.2.0
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

define( 'SMOKYFORMS_VERSION', '0.2.0' );
define( 'SMOKYFORMS_FILE', __FILE__ );
define( 'SMOKYFORMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMOKYFORMS_URL', plugin_dir_url( __FILE__ ) );

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
