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
	 * Verifies the answer.
	 *
	 * @param array  $post Posted data.
	 * @param string $ip   Visitor IP.
	 * @return string Error message, '' when the answer is right or no CAPTCHA is set up.
	 */
	public static function verify( $post, $ip ) {
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
