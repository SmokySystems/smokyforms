<?php
/**
 * Plugin-wide settings.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitizes the `smokyforms_settings` option.
 */
final class Settings {

	const OPTION = 'smokyforms_settings';

	/**
	 * CAPTCHA services that can protect forms.
	 *
	 * @return array<string,string> key => label
	 */
	public static function captcha_services() {
		return array(
			'none'      => __( 'None', 'smokyforms' ),
			'turnstile' => 'Cloudflare Turnstile',
			'recaptcha' => 'Google reCAPTCHA v2',
			'hcaptcha'  => 'hCaptcha',
		);
	}

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'captcha'          => 'none',
			'captcha_site_key' => '',
			'captcha_secret'   => '',
			'min_seconds'      => 3,
			'keep_undelivered' => 30,
		);
	}

	/**
	 * All settings merged with the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Sanitizes posted settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		$captcha = isset( $input['captcha'] ) ? sanitize_key( $input['captcha'] ) : 'none';
		$out['captcha']          = array_key_exists( $captcha, self::captcha_services() ) ? $captcha : 'none';
		$out['captcha_site_key'] = isset( $input['captcha_site_key'] ) ? sanitize_text_field( $input['captcha_site_key'] ) : '';
		$out['captcha_secret']   = isset( $input['captcha_secret'] ) ? sanitize_text_field( $input['captcha_secret'] ) : '';
		$out['min_seconds']      = isset( $input['min_seconds'] ) ? max( 0, min( 60, absint( $input['min_seconds'] ) ) ) : 3;
		$out['keep_undelivered'] = isset( $input['keep_undelivered'] ) ? max( 1, min( 365, absint( $input['keep_undelivered'] ) ) ) : 30;

		// Keep the stored secret when the field was left empty.
		if ( '' === $out['captcha_secret'] ) {
			$out['captcha_secret'] = (string) self::get( 'captcha_secret' );
		}
		return $out;
	}
}
