<?php
/**
 * Plugin bootstrap.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin parts together.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the single instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers hooks.
	 */
	private function __construct() {
		add_action( 'init', array( Forms::class, 'register' ) );
		add_action( 'init', array( Renderer::class, 'register' ) );
		add_action( 'rest_api_init', array( Submission::class, 'routes' ) );
		Submission::boot();
		Uploads::boot();
		add_action( 'rest_api_init', array( Admin\Builder::class, 'routes' ) );
		add_action( Undelivered::CRON, array( Undelivered::class, 'purge' ) );
		add_action( Undelivered::CRON, array( Uploads::class, 'purge' ) );
		add_action( 'wp_mail_failed', array( Notifications::class, 'remember_failure' ) );

		if ( is_admin() ) {
			Admin\Menu::hooks();
			Admin\Builder::hooks();
			Admin\Settings::hooks();
			Admin\UndeliveredPage::hooks();
		}
	}

	/**
	 * Activation: tables and scheduled clean-up.
	 */
	public static function activate() {
		Undelivered::install();
		if ( ! wp_next_scheduled( Undelivered::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Undelivered::CRON );
		}
		Forms::register();
		if ( ! get_option( 'smokyforms_settings' ) ) {
			add_option( 'smokyforms_settings', Settings::defaults() );
		}
	}

	/**
	 * Deactivation: stop the scheduled clean-up. Data stays until uninstall.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Undelivered::CRON );
	}
}
