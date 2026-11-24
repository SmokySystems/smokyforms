<?php
/**
 * Form submissions.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms;

defined( 'ABSPATH' ) || exit;

/**
 * Validates submissions, recomputes calculations on the server and sends the e-mails.
 * Free does not store entries; SmokyForms Pro and add-ons can use the `smokyforms_submitted` action.
 */
final class Submission {

	/**
	 * REST route for the JavaScript submit.
	 */
	public static function routes() {
		register_rest_route(
			'smokyforms/v1',
			'/submit/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest' ),
				'permission_callback' => '__return_true', // Public forms; protected by the spam checks below.
				'args'                => array(
					'id' => array(
						'validate_callback' => static function ( $v ) {
							return is_numeric( $v );
						},
					),
				),
			)
		);
	}

	/**
	 * Admin-post registration must happen on every request, not only REST ones.
	 */
	public static function boot() {
		add_action( 'admin_post_smokyforms_submit', array( __CLASS__, 'fallback' ) );
		add_action( 'admin_post_nopriv_smokyforms_submit', array( __CLASS__, 'fallback' ) );
	}

	/**
	 * REST callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function rest( $request ) {
		$result = self::process( (int) $request['id'], $request->get_body_params() );
		return new \WP_REST_Response( $result, $result['ok'] ? 200 : ( $result['status'] ?? 422 ) );
	}

	/**
	 * Submission without JavaScript: process, then go back to the page with a message.
	 */
	public static function fallback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public form, protected by the spam checks.
		$id     = isset( $_POST['smokyforms_id'] ) ? absint( $_POST['smokyforms_id'] ) : 0;
		$result = self::process( $id, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$back   = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		$back   = remove_query_arg( array( 'smokyforms_sent', 'smokyforms_error' ), $back );
		if ( $result['ok'] && ! empty( $result['redirect'] ) ) {
			wp_safe_redirect( $result['redirect'] );
			exit;
		}
		$arg = $result['ok'] ? 'smokyforms_sent' : 'smokyforms_error';
		wp_safe_redirect( add_query_arg( $arg, $id, $back ) . '#smokyforms-' . $id . '-1' );
		exit;
	}

	/**
	 * Signed time stamp put into every form, to recognise bots that submit instantly.
	 *
	 * @return string
	 */
	public static function time_token() {
		$t = (string) time();
		return $t . '.' . substr( hash_hmac( 'sha256', $t, wp_salt( 'nonce' ) ), 0, 16 );
	}

	/**
	 * Handles one submission.
	 *
	 * @param int        $id    Form id.
	 * @param array      $post  Posted data (slashes removed or not, values are cleaned per field).
	 * @param array|null $files Uploaded files in the $_FILES format (default: $_FILES).
	 * @return array ok, message, errors, redirect
	 */
	public static function process( $id, $post, $files = null ) {
		$form = Forms::get( $id );
		if ( ! $form || 'publish' !== $form['status'] ) {
			return array(
				'ok'      => false,
				'status'  => 404,
				'message' => __( 'This form does not exist.', 'smokyforms' ),
			);
		}

		$success = array(
			'ok'       => true,
			'message'  => wpautop( Notifications::replace( $form['settings']['success_message'], $form, array(), true ) ),
			'redirect' => $form['settings']['redirect'],
		);

		// Honeypot: bots fill every field. Answer as if it worked, so they learn nothing.
		if ( ! empty( $post['smokyforms_website'] ) ) {
			return $success;
		}

		$spam = self::spam_check( $form, $post );
		if ( $spam ) {
			return array(
				'ok'      => false,
				'status'  => 400,
				'message' => $spam,
				'errors'  => array(),
			);
		}

		$raw     = isset( $post['smokyforms'] ) && is_array( $post['smokyforms'] ) ? $post['smokyforms'] : array();
		$files   = null === $files ? $_FILES : $files; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$values  = array();
		$errors  = array();
		$pending = array();
		foreach ( $form['fields'] as $f ) {
			if ( ! Fields::is_input( $f['type'] ) || 'calculation' === $f['type'] ) {
				continue;
			}
			if ( 'repeater' === $f['type'] ) {
				list( $value, $errs ) = Fields::validate_rows( $f, $raw[ $f['id'] ] ?? null );
				$values[ $f['id'] ]   = $value;
				$errors               = array_merge( $errors, $errs );
				continue;
			}
			if ( 'file' === $f['type'] ) {
				list( $value, $error ) = Uploads::validate( $f, Uploads::from_request( $files, $f['id'] ) );
				$pending[ $f['id'] ]   = $value;
				$values[ $f['id'] ]    = array();
			} else {
				list( $value, $error ) = Fields::validate( $f, $raw[ $f['id'] ] ?? null );
				$values[ $f['id'] ]    = $value;
			}
			if ( '' !== $error ) {
				$errors[ $f['id'] ] = $error;
			}
		}

		// Conditional logic: hidden fields count as empty, are not checked and are left out of the e-mails.
		$hidden = array();
		for ( $pass = 0; $pass < 5; $pass++ ) {
			$visible = array_diff_key( $values, $hidden );
			$now     = Logic::hidden( $form, Forms::calculate( $form, $visible ) );
			if ( $now === $hidden ) {
				break;
			}
			$hidden = $now;
		}
		foreach ( array_keys( $errors ) as $key ) {
			if ( isset( $hidden[ explode( '.', (string) $key )[0] ] ) ) {
				unset( $errors[ $key ] );
			}
		}
		$values = array_diff_key( $values, $hidden );

		$errors = apply_filters( 'smokyforms_validation_errors', $errors, $form, $values );
		if ( $errors ) {
			return array(
				'ok'      => false,
				'status'  => 422,
				'message' => __( 'Please check the form and try again.', 'smokyforms' ),
				'errors'  => $errors,
			);
		}

		// Never trust a total from the browser: calculations are done again here.
		$values = array_diff_key( Forms::calculate( $form, $values ), $hidden );

		// Files are saved only now that the whole submission is valid.
		$values = Uploads::store( $form, $values, $pending );

		$page              = isset( $post['_wp_http_referer'] ) ? esc_url_raw( wp_unslash( $post['_wp_http_referer'] ) ) : (string) wp_get_raw_referer();
		$values['_meta']   = Notifications::context( $page, isset( $post['smokyforms_referrer'] ) && is_string( $post['smokyforms_referrer'] ) ? wp_unslash( $post['smokyforms_referrer'] ) : '' );
		$entry             = array(
			'form_id' => $form['id'],
			'values'  => $values,
			'page'    => $page,
			'date'    => current_time( 'mysql', true ),
		);

		/**
		 * Fires after a valid submission, before the e-mails go out. SmokyForms Pro stores entries here.
		 *
		 * @param array $entry Form id, values by field id, page and date (UTC).
		 * @param array $form  Form.
		 */
		do_action( 'smokyforms_submitted', $entry, $form );

		Notifications::send( $form, $values );

		$success['message'] = wpautop( Notifications::replace( $form['settings']['success_message'], $form, $values, true ) );
		$success            = apply_filters( 'smokyforms_success_response', $success, $entry, $form );
		return $success;
	}

	/**
	 * Time check, rate limit and CAPTCHA.
	 *
	 * @param array $form Form.
	 * @param array $post Posted data.
	 * @return string Error message, '' when the submission may go on.
	 */
	private static function spam_check( $form, $post ) {
		$token = isset( $post['smokyforms_t'] ) ? (string) $post['smokyforms_t'] : '';
		$parts = explode( '.', $token );
		$min   = (int) Settings::get( 'min_seconds' );
		if ( 2 !== count( $parts ) || ! hash_equals( substr( hash_hmac( 'sha256', $parts[0], wp_salt( 'nonce' ) ), 0, 16 ), $parts[1] ) ) {
			return __( 'The form could not be checked. Please reload the page and try again.', 'smokyforms' );
		}
		if ( $min > 0 && time() - (int) $parts[0] < $min ) {
			return __( 'That was very fast. Please wait a moment and send the form again.', 'smokyforms' );
		}

		// At most 10 submissions per minute per visitor and form.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'smokyforms_rl_' . md5( $form['id'] . '|' . $ip . '|' . wp_salt( 'nonce' ) );
		$hit = (int) get_transient( $key );
		if ( $hit >= (int) apply_filters( 'smokyforms_rate_limit', 10, $form ) ) {
			return __( 'Too many submissions. Please wait a minute and try again.', 'smokyforms' );
		}
		set_transient( $key, $hit + 1, MINUTE_IN_SECONDS );

		if ( ! empty( $form['settings']['captcha'] ) ) {
			return Captcha::verify( $post, $ip );
		}
		return '';
	}
}
