<?php
/**
 * Loader of the shared SMTP module of the SmokySystems plugins.
 *
 * Every SmokySystems plugin ships its own copy of this folder. Each copy registers its version;
 * when all plugins are loaded, only the newest copy is loaded, so the settings live in one place
 * and the mail hooks are added once.
 *
 * Copying into another plugin: copy the folder and replace the text domain 'smokyforms'
 * with that plugin's text domain (the strings are translated in each plugin's language files).
 *
 * @package SmokySystems\Mail
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'smokysystems_mail_register' ) ) {
	/**
	 * Registers one copy of the module.
	 *
	 * @param string $version Module version.
	 * @param string $dir     Folder of the copy.
	 * @param string $plugin  Name of the plugin that ships the copy.
	 */
	function smokysystems_mail_register( $version, $dir, $plugin ) {
		if ( ! isset( $GLOBALS['smokysystems_mail_versions'] ) ) {
			$GLOBALS['smokysystems_mail_versions'] = array();
		}
		if ( ! isset( $GLOBALS['smokysystems_mail_plugins'] ) ) {
			$GLOBALS['smokysystems_mail_plugins'] = array();
		}
		$GLOBALS['smokysystems_mail_versions'][ $version ] = $dir;
		$GLOBALS['smokysystems_mail_plugins'][]            = $plugin;
	}

	add_action(
		'plugins_loaded',
		static function () {
			$versions = $GLOBALS['smokysystems_mail_versions'];
			uksort( $versions, 'version_compare' );
			$dir = end( $versions );
			require_once $dir . '/Module.php';
			\SmokySystems\Mail\Module::init();
		},
		-100
	);
}

smokysystems_mail_register( '1.0.0', __DIR__, 'SmokyForms' );
