<?php
/**
 * Removes SmokyForms data when the plugin is deleted.
 *
 * The forms (posts of the type smokyforms_form) are deleted too. The shared SMTP settings
 * (option smokysystems_mail) stay when another SmokySystems plugin is still installed.
 *
 * @package SmokyForms
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$smokyforms_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'smokyforms_form' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( $smokyforms_ids as $smokyforms_id ) {
	wp_delete_post( (int) $smokyforms_id, true );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}smokyforms_undelivered" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange

delete_option( 'smokyforms_settings' );
delete_option( 'smokyforms_db_version' );
delete_transient( 'smokyforms_undelivered_count' );
wp_clear_scheduled_hook( 'smokyforms_purge_undelivered' );

// The SMTP settings are shared with other SmokySystems plugins: only remove them when none is left.
if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$smokyforms_others = false;
foreach ( array_keys( get_plugins() ) as $smokyforms_plugin ) {
	if ( 0 === strpos( $smokyforms_plugin, 'smokypost/' ) || 0 === strpos( $smokyforms_plugin, 'smokyautoblog/' ) ) {
		$smokyforms_others = true;
	}
}
if ( ! $smokyforms_others ) {
	delete_option( 'smokysystems_mail' );
}
