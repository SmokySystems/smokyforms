<?php
/**
 * Notification and confirmation e-mails.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and sends the e-mails of a submission. When the notification cannot be sent,
 * the submission is kept for a while under SmokyForms > Undelivered so it is not lost.
 */
final class Notifications {

	/**
	 * Last wp_mail error during our send.
	 *
	 * @var string
	 */
	private static $last_error = '';

	/**
	 * Whether we are sending right now (so remember_failure() only catches our own mail).
	 *
	 * @var bool
	 */
	private static $sending = false;

	/**
	 * Smart tags for the e-mail settings, shown in the builder.
	 *
	 * @return array<string,string> tag => description
	 */
	public static function tags() {
		return array(
			'{all_fields}'           => __( 'All answers', 'smokyforms' ),
			'{unique_value}'         => __( 'Unique value (a reference number for each entry)', 'smokyforms' ),
			'{form_title}'           => __( 'Form name', 'smokyforms' ),
			'{form_id}'              => __( 'Form ID', 'smokyforms' ),
			'{date}'                 => __( 'Date and time', 'smokyforms' ),
			'{date:d.m.Y}'           => __( 'Date in your own format', 'smokyforms' ),
			'{site_name}'            => __( 'Site name', 'smokyforms' ),
			'{site_url}'             => __( 'Site address', 'smokyforms' ),
			'{admin_email}'          => __( 'Site admin email', 'smokyforms' ),
			'{page_url}'             => __( 'Page of the form', 'smokyforms' ),
			'{page_title}'           => __( 'Title of the form page', 'smokyforms' ),
			'{page_id}'              => __( 'ID of the form page', 'smokyforms' ),
			'{query_var:utm_source}' => __( 'Parameter in the page address', 'smokyforms' ),
			'{url_referer}'          => __( 'Page the visitor came from', 'smokyforms' ),
			'{author_display}'       => __( 'Author of the form page', 'smokyforms' ),
			'{author_email}'         => __( 'Email of the page author', 'smokyforms' ),
			'{author_id}'            => __( 'ID of the page author', 'smokyforms' ),
			'{user_ip}'              => __( 'Visitor IP address', 'smokyforms' ),
			'{user_id}'              => __( 'Logged-in user: ID', 'smokyforms' ),
			'{user_display}'         => __( 'Logged-in user: display name', 'smokyforms' ),
			'{user_full_name}'       => __( 'Logged-in user: full name', 'smokyforms' ),
			'{user_first_name}'      => __( 'Logged-in user: first name', 'smokyforms' ),
			'{user_last_name}'       => __( 'Logged-in user: last name', 'smokyforms' ),
			'{user_email}'           => __( 'Logged-in user: email', 'smokyforms' ),
			'{user_meta:nickname}'   => __( 'Logged-in user: profile field', 'smokyforms' ),
			'{url_login}'            => __( 'Login address', 'smokyforms' ),
			'{url_logout}'           => __( 'Logout address', 'smokyforms' ),
			'{url_register}'         => __( 'Registration address', 'smokyforms' ),
			'{url_lost_password}'    => __( 'Lost password address', 'smokyforms' ),
		);
	}

	/**
	 * Details of one submission that smart tags use, kept with the values (key "_meta")
	 * so that a resend from Undelivered shows the same reference number, page and visitor.
	 *
	 * @param string $page     Page of the form.
	 * @param string $referrer Page the visitor came from.
	 * @return array
	 */
	public static function context( $page = '', $referrer = '' ) {
		return array(
			'unique'   => strtoupper( bin2hex( random_bytes( 6 ) ) ),
			'page'     => $page ? esc_url_raw( $page ) : '',
			'referrer' => $referrer ? esc_url_raw( $referrer ) : '',
			'ip'       => self::ip(),
			'user'     => get_current_user_id(),
			'time'     => time(),
		);
	}

	/**
	 * Sends the notification (and the confirmation, when set).
	 *
	 * @param array $form   Form.
	 * @param array $values Values by field id.
	 * @return bool Whether the notification went out (true when notifications are off).
	 */
	public static function send( $form, $values ) {
		$s  = $form['settings'];
		$ok = true;

		$notify = apply_filters( 'smokyforms_notification', $s['notify'], $form, $values );
		if ( ! empty( $notify['enabled'] ) ) {
			$to = self::recipients( self::replace( $notify['to'], $form, $values, false ) );
			if ( $to ) {
				$headers = array( 'Content-Type: text/html; charset=UTF-8' );
				$reply   = self::first_email( $form, $values );
				if ( $reply ) {
					$headers[] = 'Reply-To: ' . $reply;
				}
				$subject = self::replace( $notify['subject'], $form, $values, false );
				$body    = self::html( self::replace( $notify['message'], $form, $values, true ) );
				$ok      = self::mail( $to, $subject, $body, $headers, Uploads::attachments( $form, $values ) );
				if ( ! $ok ) {
					Undelivered::store( $form, $values, $to, self::$last_error );
				}
			}
		}

		$confirm = apply_filters( 'smokyforms_confirmation', $s['confirm'], $form, $values );
		if ( ! empty( $confirm['enabled'] ) && '' !== $confirm['field'] ) {
			$address = isset( $values[ $confirm['field'] ] ) ? (string) $values[ $confirm['field'] ] : '';
			if ( is_email( $address ) ) {
				$subject = self::replace( $confirm['subject'], $form, $values, false );
				$body    = self::html( self::replace( $confirm['message'], $form, $values, true ) );
				self::mail( $address, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
			}
		}
		return $ok;
	}

	/**
	 * Sends one e-mail and records why it failed.
	 *
	 * @param string|string[] $to      Recipients.
	 * @param string          $subject Subject.
	 * @param string          $body    HTML body.
	 * @param string[]        $headers Headers.
	 * @param string[]        $files   Attachments.
	 * @return bool
	 */
	public static function mail( $to, $subject, $body, $headers, $files = array() ) {
		self::$last_error = '';
		self::$sending    = true;
		$send             = static function () use ( $to, $subject, $body, $headers, $files ) {
			return wp_mail( $to, $subject, $body, $headers, $files );
		};
		// Sends through the SMTP account when one is set up (Settings > Email sending).
		$ok = Mail::wrap( $send );
		self::$sending    = false;
		if ( ! $ok && '' === self::$last_error ) {
			self::$last_error = __( 'The site could not send the email.', 'smokyforms' );
		}
		return (bool) $ok;
	}

	/**
	 * Keeps the error message of a failed wp_mail() call made by us.
	 *
	 * @param \WP_Error $error Error.
	 */
	public static function remember_failure( $error ) {
		if ( self::$sending && is_wp_error( $error ) ) {
			self::$last_error = $error->get_error_message();
		}
	}

	/**
	 * Valid addresses from a comma separated list.
	 *
	 * @param string $list Addresses.
	 * @return string[]
	 */
	public static function recipients( $list ) {
		$out = array();
		foreach ( preg_split( '/[,;\s]+/', (string) $list ) as $a ) {
			$a = sanitize_email( $a );
			if ( is_email( $a ) ) {
				$out[] = $a;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * First answered e-mail field (used as Reply-To).
	 *
	 * @param array $form   Form.
	 * @param array $values Values.
	 * @return string
	 */
	private static function first_email( $form, $values ) {
		foreach ( $form['fields'] as $f ) {
			if ( 'email' === $f['type'] && ! empty( $values[ $f['id'] ] ) && is_email( $values[ $f['id'] ] ) ) {
				return $values[ $f['id'] ];
			}
		}
		return '';
	}

	/**
	 * Replaces smart tags. Field answers can be used as {field:f12}.
	 *
	 * @param string $text   Text with tags.
	 * @param array  $form   Form.
	 * @param array  $values Values.
	 * @param bool   $html   Whether the result is HTML (values are escaped, {all_fields} is a table).
	 * @return string
	 */
	public static function replace( $text, $form, $values, $html ) {
		$meta = isset( $values['_meta'] ) && is_array( $values['_meta'] ) ? $values['_meta'] : self::context( (string) wp_get_raw_referer() );
		$page = (string) ( $meta['page'] ?? '' );
		$time = (int) ( $meta['time'] ?? time() );
		$pid  = $page ? url_to_postid( $page ) : 0;
		$post = $pid ? get_post( $pid ) : null;
		$auth = $post ? get_userdata( (int) $post->post_author ) : false;
		$user = ! empty( $meta['user'] ) ? get_userdata( (int) $meta['user'] ) : false;
		$map  = array(
			'{unique_value}'      => (string) ( $meta['unique'] ?? '' ),
			'{form_title}'        => $form['title'],
			'{form_id}'           => (string) $form['id'],
			'{site_name}'         => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{site_url}'          => home_url( '/' ),
			'{admin_email}'       => get_option( 'admin_email' ),
			'{date}'              => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ),
			'{page_url}'          => $page,
			'{page_title}'        => $post ? wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ) : '',
			'{page_id}'           => $pid ? (string) $pid : '',
			'{url_referer}'       => (string) ( $meta['referrer'] ?? '' ),
			'{author_display}'    => $auth ? $auth->display_name : '',
			'{author_email}'      => $auth ? $auth->user_email : '',
			'{author_id}'         => $auth ? (string) $auth->ID : '',
			'{user_ip}'           => (string) ( $meta['ip'] ?? '' ),
			'{user_id}'           => $user ? (string) $user->ID : '',
			'{user_display}'      => $user ? $user->display_name : '',
			'{user_full_name}'    => $user ? trim( $user->first_name . ' ' . $user->last_name ) : '',
			'{user_first_name}'   => $user ? $user->first_name : '',
			'{user_last_name}'    => $user ? $user->last_name : '',
			'{user_email}'        => $user ? $user->user_email : '',
			'{url_login}'         => wp_login_url(),
			'{url_logout}'        => wp_logout_url(),
			'{url_register}'      => wp_registration_url(),
			'{url_lost_password}' => wp_lostpassword_url(),
		);
		// Tags with a value of their own: {date:FORMAT}, {query_var:NAME}, {user_meta:KEY}.
		$args = array();
		parse_str( (string) wp_parse_url( $page, PHP_URL_QUERY ), $args );
		$text = preg_replace_callback(
			'/\{(date|query_var|user_meta):([^}]{1,60})\}/',
			static function ( $m ) use ( $time, $args, $user, &$map ) {
				$v = '';
				if ( 'date' === $m[1] ) {
					$v = wp_date( $m[2], $time );
				} elseif ( 'query_var' === $m[1] ) {
					$v = isset( $args[ $m[2] ] ) && is_scalar( $args[ $m[2] ] ) ? sanitize_text_field( (string) $args[ $m[2] ] ) : '';
				} elseif ( $user && ! is_protected_meta( $m[2], 'user' ) && false === stripos( $m[2], 'pass' ) && false === stripos( $m[2], 'token' ) && false === stripos( $m[2], 'session' ) ) {
					$meta = get_user_meta( $user->ID, $m[2], true );
					$v    = is_scalar( $meta ) ? (string) $meta : '';
				}
				$map[ $m[0] ] = (string) $v;
				return $m[0];
			},
			$text
		);
		if ( $html ) {
			// The text itself may contain HTML (kept to what posts allow); the answers are escaped.
			$map  = array_map( 'esc_html', $map );
			$text = wp_kses_post( $text );
			$map['{all_fields}'] = self::table( $form, $values );
		} else {
			$map['{all_fields}'] = self::plain( $form, $values );
		}
		$text = strtr( $text, $map );
		return preg_replace_callback(
			'/\{field:([a-z0-9_]+)\}/',
			static function ( $m ) use ( $form, $values, $html ) {
				$f = Forms::field( $form, $m[1] );
				if ( ! $f ) {
					return '';
				}
				$v = Fields::display( $f, $values[ $f['id'] ] ?? '' );
				return $html ? esc_html( $v ) : $v;
			},
			$text
		);
	}

	/**
	 * IP address of the visitor.
	 *
	 * @return string
	 */
	private static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Answers as an HTML table.
	 *
	 * @param array $form   Form.
	 * @param array $values Values.
	 * @return string
	 */
	public static function table( $form, $values ) {
		$rows = '';
		foreach ( $form['fields'] as $f ) {
			// Fields hidden by conditional logic have no value at all and are left out.
			if ( ! Fields::is_input( $f['type'] ) || ! array_key_exists( $f['id'], $values ) || 'hidden' === $f['type'] && '' === $values[ $f['id'] ] ) {
				continue;
			}
			$v     = Fields::display_html( $f, $values[ $f['id'] ] );
			$label = '' !== $f['label'] ? $f['label'] : $f['id'];
			$rows .= '<tr><th style="text-align:left;vertical-align:top;padding:8px 12px 8px 0;border-bottom:1px solid #e5e5e5;">' . esc_html( $label ) . '</th>'
				. '<td style="padding:8px 0;border-bottom:1px solid #e5e5e5;">' . ( '' === $v ? '&ndash;' : $v ) . '</td></tr>';
		}
		return '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:640px;">' . $rows . '</table>';
	}

	/**
	 * Answers as plain text lines.
	 *
	 * @param array $form   Form.
	 * @param array $values Values.
	 * @return string
	 */
	private static function plain( $form, $values ) {
		$lines = array();
		foreach ( $form['fields'] as $f ) {
			if ( Fields::is_input( $f['type'] ) && array_key_exists( $f['id'], $values ) ) {
				$v       = Fields::display( $f, $values[ $f['id'] ] );
				$lines[] = ( '' !== $f['label'] ? $f['label'] : $f['id'] ) . ':' . ( false !== strpos( $v, "\n" ) ? "\n" : ' ' ) . $v;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Wraps a body in a minimal HTML document; line breaks of the text become <br>.
	 *
	 * @param string $content Escaped content with optional table.
	 * @return string
	 */
	public static function html( $content ) {
		$parts = preg_split( '/(<table.*?<\/table>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		foreach ( $parts as $p ) {
			$out .= 0 === strpos( $p, '<table' ) ? $p : wpautop( $p );
		}
		return '<!DOCTYPE html><html><body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1e1e1e;">' . $out . '</body></html>';
	}
}
