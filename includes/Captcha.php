<?php
/**
 * CAPTCHA verification.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the CAPTCHA answer with the provider chosen under Settings.
 */
final class Captcha {

	/**
	 * How long a math question stays valid (a cached page keeps showing the same one).
	 */
	const MATH_TTL = 3 * DAY_IN_SECONDS;

	/**
	 * Whether forms with the CAPTCHA switched on also ask a math question.
	 *
	 * @return bool
	 */
	public static function math_enabled() {
		return (bool) Settings::get( 'math' );
	}

	/**
	 * A new math question. The answer is not sent to the browser; the signed token only proves
	 * that the question was made by this site, so nothing is stored when a page is shown.
	 *
	 * @return array{a:int,b:int,symbol:string,token:string}
	 */
	public static function challenge() {
		$kind = wp_rand( 0, 2 );
		if ( 0 === $kind ) {
			$a = wp_rand( 1, 9 );
			$b = wp_rand( 1, 9 );
			$op = 'p';
		} elseif ( 1 === $kind ) {
			$a = wp_rand( 5, 15 );
			$b = wp_rand( 1, min( 9, $a - 1 ) );
			$op = 'm';
		} else {
			$a = wp_rand( 2, 5 );
			$b = wp_rand( 2, 5 );
			$op = 'x';
		}
		$payload = $a . '.' . $b . '.' . $op . '.' . time();
		$symbols = array(
			'p' => '+',
			'm' => "\u{2212}",
			'x' => "\u{00D7}",
		);
		return array(
			'a'      => $a,
			'b'      => $b,
			'symbol' => $symbols[ $op ],
			'token'  => $payload . '.' . self::sign( $payload ),
		);
	}

	/**
	 * Signature of a math question.
	 *
	 * @param string $payload a.b.op.time
	 * @return string
	 */
	private static function sign( $payload ) {
		return substr( hash_hmac( 'sha256', 'smokyforms-math|' . $payload, wp_salt( 'nonce' ) ), 0, 20 );
	}

	/**
	 * Checks the answer to the math question.
	 *
	 * @param array $post Posted data.
	 * @return string Error message, '' when the answer is right.
	 */
	public static function verify_math( $post ) {
		$token  = isset( $post['smokyforms_math_token'] ) ? (string) $post['smokyforms_math_token'] : '';
		$answer = isset( $post['smokyforms_math'] ) ? trim( sanitize_text_field( (string) $post['smokyforms_math'] ) ) : '';
		$parts  = explode( '.', $token );
		if ( 5 !== count( $parts ) || ! hash_equals( self::sign( implode( '.', array_slice( $parts, 0, 4 ) ) ), $parts[4] ) ) {
			return __( 'The form could not be checked. Please reload the page and try again.', 'smokyforms' );
		}
		$age = time() - (int) $parts[3];
		if ( $age < 0 || $age > self::MATH_TTL || get_transient( 'smokyforms_m_' . $parts[4] ) ) {
			return __( 'The form could not be checked. Please reload the page and try again.', 'smokyforms' );
		}
		if ( '' === $answer ) {
			return __( 'Please answer the math question.', 'smokyforms' );
		}
		$a = (int) $parts[0];
		$b = (int) $parts[1];
		if ( 'p' === $parts[2] ) {
			$right = $a + $b;
		} elseif ( 'm' === $parts[2] ) {
			$right = $a - $b;
		} else {
			$right = $a * $b;
		}
		if ( ! preg_match( '/^\d{1,3}$/', $answer ) || (int) $answer !== $right ) {
			return __( 'The answer to the math question is not right.', 'smokyforms' );
		}
		return '';
	}

	/**
	 * Marks the question as used, so one solved question cannot be sent again.
	 *
	 * @param array $post Posted data.
	 */
	public static function use_math( $post ) {
		$token = isset( $post['smokyforms_math_token'] ) ? (string) $post['smokyforms_math_token'] : '';
		$parts = explode( '.', $token );
		if ( 5 === count( $parts ) ) {
			set_transient( 'smokyforms_m_' . $parts[4], 1, self::MATH_TTL );
		}
	}

	/**
	 * Verifies the answer.
	 *
	 * @param array  $post Posted data.
	 * @param string $ip   Visitor IP.
	 * @return string Error message, '' when the answer is right or no CAPTCHA is set up.
	 */
	public static function verify( $post, $ip ) {
		if ( self::math_enabled() ) {
			$error = self::verify_math( $post );
			if ( '' !== $error ) {
				return $error;
			}
		}
		$service = Settings::get( 'captcha' );
		$secret  = Settings::get( 'captcha_secret' );
		if ( 'none' === $service || '' === (string) Settings::get( 'captcha_site_key' ) || '' === (string) $secret ) {
			return '';
		}
		$map = array(
			'turnstile' => array( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', 'cf-turnstile-response' ),
			'recaptcha' => array( 'https://www.google.com/recaptcha/api/siteverify', 'g-recaptcha-response' ),
			'hcaptcha'  => array( 'https://api.hcaptcha.com/siteverify', 'h-captcha-response' ),
		);
		if ( ! isset( $map[ $service ] ) ) {
			return '';
		}
		$answer = isset( $post[ $map[ $service ][1] ] ) ? sanitize_text_field( (string) $post[ $map[ $service ][1] ] ) : '';
		if ( '' === $answer ) {
			return __( 'Please confirm that you are not a robot.', 'smokyforms' );
		}
		$response = wp_remote_post(
			$map[ $service ][0],
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $answer,
					'remoteip' => $ip,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			// The provider cannot be reached: let the visitor through rather than lose the message.
			return '';
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['success'] ) ) {
			return __( 'The robot check failed. Please try again.', 'smokyforms' );
		}
		return '';
	}
}
