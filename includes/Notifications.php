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
			'{all_fields}'  => __( 'All answers', 'smokyforms' ),
			'{form_title}'  => __( 'Form name', 'smokyforms' ),
			'{site_name}'   => __( 'Site name', 'smokyforms' ),
			'{admin_email}' => __( 'Site admin email', 'smokyforms' ),
			'{date}'        => __( 'Date and time', 'smokyforms' ),
			'{page_url}'    => __( 'Page of the form', 'smokyforms' ),
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
		// The shared SMTP module sends through the SMTP account when one is set up.
		$ok = class_exists( '\SmokySystems\Mail\Module' ) ? \SmokySystems\Mail\Module::wrap( $send ) : $send();
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
		$page = wp_get_raw_referer();
		$map  = array(
			'{form_title}'  => $form['title'],
			'{site_name}'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{admin_email}' => get_option( 'admin_email' ),
			'{date}'        => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'{page_url}'    => $page ? esc_url_raw( $page ) : '',
		);
		if ( $html ) {
			$map = array_map( 'esc_html', $map );
			$text = esc_html( $text );
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
			$out .= 0 === strpos( $p, '<table' ) ? $p : nl2br( $p );
		}
		return '<!DOCTYPE html><html><body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1e1e1e;">' . $out . '</body></html>';
	}
}
