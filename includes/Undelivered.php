<?php
/**
 * Safety net for notifications that could not be e-mailed.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms;

defined( 'ABSPATH' ) || exit;

/**
 * Free does not store entries. Only when a notification e-mail fails is the submission kept,
 * for the number of days set under Settings, so it can be read or sent again.
 */
final class Undelivered {

	const CRON = 'smokyforms_purge_undelivered';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'smokyforms_undelivered';
	}

	/**
	 * Creates the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL DEFAULT 0,
				recipients text NOT NULL,
				data longtext NOT NULL,
				error text NOT NULL,
				created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY created (created)
			) {$charset};"
		);
		update_option( 'smokyforms_db_version', 1 );
	}

	/**
	 * Makes sure the table exists (for sites that got the plugin without activation, e.g. multisite).
	 */
	private static function ensure() {
		if ( 1 !== (int) get_option( 'smokyforms_db_version' ) ) {
			self::install();
		}
	}

	/**
	 * Keeps a submission whose notification failed.
	 *
	 * @param array    $form   Form.
	 * @param array    $values Values.
	 * @param string[] $to     Recipients.
	 * @param string   $error  Error message.
	 */
	public static function store( $form, $values, $to, $error ) {
		global $wpdb;
		self::ensure();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'form_id'    => (int) $form['id'],
				'recipients' => implode( ', ', (array) $to ),
				'data'       => wp_json_encode( $values ),
				'error'      => (string) $error,
				'created'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
		delete_transient( 'smokyforms_undelivered_count' );
	}

	/**
	 * Number of kept submissions.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$n = get_transient( 'smokyforms_undelivered_count' );
		if ( false === $n ) {
			self::ensure();
			$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			set_transient( 'smokyforms_undelivered_count', $n, HOUR_IN_SECONDS );
		}
		return (int) $n;
	}

	/**
	 * Kept submissions, newest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,object>
	 */
	public static function all( $limit = 200 ) {
		global $wpdb;
		self::ensure();
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * One kept submission.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		self::ensure();
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Deletes one kept submission.
	 *
	 * @param int $id Row id.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		delete_transient( 'smokyforms_undelivered_count' );
	}

	/**
	 * Tries to send a kept submission again; deletes it when that works.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public static function resend( $id ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return false;
		}
		$form = Forms::get( (int) $row->form_id );
		if ( ! $form ) {
			return false;
		}
		$values = json_decode( $row->data, true );
		$values = is_array( $values ) ? $values : array();
		$to     = Notifications::recipients( $row->recipients );
		if ( ! $to ) {
			return false;
		}
		$subject = Notifications::replace( $form['settings']['notify']['subject'], $form, $values, false );
		$body    = Notifications::html( Notifications::replace( $form['settings']['notify']['message'], $form, $values, true ) );
		$ok      = Notifications::mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		if ( $ok ) {
			self::delete( $id );
		}
		return $ok;
	}

	/**
	 * Daily clean-up of old rows.
	 */
	public static function purge() {
		global $wpdb;
		self::ensure();
		$days = (int) Settings::get( 'keep_undelivered' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created < %s', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		delete_transient( 'smokyforms_undelivered_count' );
	}
}
