<?php
/**
 * Removes SmokyForms data when the plugin is deleted.
 *
 * The forms (posts of the type smokyforms_form) are deleted too.
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

delete_option( 'smokyforms_mail' );
delete_option( 'smokysystems_mail' ); // Kept by versions before 0.2.9.
