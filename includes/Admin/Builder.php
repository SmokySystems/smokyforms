<?php
/**
 * Form builder screen and its REST routes.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms\Admin;

use SmokySystems\SmokyForms\Fields;
use SmokySystems\SmokyForms\Forms;
use SmokySystems\SmokyForms\Notifications;
use SmokySystems\SmokyForms\Settings as PluginSettings;
use SmokySystems\SmokyForms\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * The builder is a small app (assets/js/builder.js) on top of WordPress' own React components.
 */
final class Builder {

	const SLUG = 'smokyforms-builder';

	/**
	 * Registers hooks.
	 */
	public static function hooks() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'admin_title', array( __CLASS__, 'title' ), 10, 2 );
	}

	/**
	 * REST routes: create and update.
	 */
	public static function routes() {
		$args = array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save' ),
			'permission_callback' => static function () {
				return current_user_can( Menu::cap() );
			},
		);
		register_rest_route( 'smokyforms/v1', '/forms', $args );
		register_rest_route( 'smokyforms/v1', '/forms/(?P<id>\d+)', $args );
		register_rest_route(
			'smokyforms/v1',
			'/forms',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'index' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * Saves a form from the builder.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save( $request ) {
		$id = isset( $request['id'] ) ? (int) $request['id'] : 0;
		if ( $id && Forms::POST_TYPE !== get_post_type( $id ) ) {
			return new \WP_Error( 'smokyforms_not_found', __( 'This form does not exist.', 'smokyforms' ), array( 'status' => 404 ) );
		}
		$body  = $request->get_json_params();
		$title = isset( $body['title'] ) && '' !== trim( (string) $body['title'] ) ? (string) $body['title'] : __( 'Untitled form', 'smokyforms' );
		$saved = Forms::save( $id, $title, is_array( $body['form'] ?? null ) ? $body['form'] : array() );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$form = Forms::get( $saved );
		return new \WP_REST_Response(
			array(
				'id'      => $saved,
				'form'    => self::client_form( $form ),
				'message' => __( 'Form saved.', 'smokyforms' ),
			)
		);
	}

	/**
	 * Published forms for the block's form picker.
	 *
	 * @return \WP_REST_Response
	 */
	public static function index() {
		$posts = get_posts(
			array(
				'post_type'      => Forms::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out   = array();
		foreach ( $posts as $p ) {
			$out[] = array(
				'id'    => $p->ID,
				'title' => '' !== $p->post_title ? $p->post_title : __( 'Untitled form', 'smokyforms' ),
			);
		}
		return new \WP_REST_Response( $out );
	}

	/**
	 * Form data for the builder.
	 *
	 * @param array|null $form Form.
	 * @return array|null
	 */
	private static function client_form( $form ) {
		if ( ! $form ) {
			return null;
		}
		return array(
			'id'       => $form['id'],
			'title'    => $form['title'],
			'fields'   => $form['fields'],
			'tables'   => $form['tables'],
			'settings' => $form['settings'],
		);
	}

	/**
	 * Browser tab title of the builder.
	 *
	 * @param string $admin_title Title.
	 * @param string $title       Page title.
	 * @return string
	 */
	public static function title( $admin_title, $title ) {
		if ( isset( $_GET['page'] ) && self::SLUG === $_GET['page'] && ! empty( $_GET['form'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			/* translators: %s: name of the site */
			return sprintf( __( 'Edit Form &lsaquo; %s', 'smokyforms' ), get_bloginfo( 'name' ) );
		}
		return $admin_title;
	}

	/**
	 * Scripts and data of the builder.
	 *
	 * @param string $hook Screen hook.
	 */
	public static function assets( $hook ) {
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$id   = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form = $id ? Forms::get( $id ) : null;

		wp_enqueue_style( 'wp-components' );
		// Classic editor (Visual / Code tabs) for the messages that may contain HTML.
		wp_enqueue_editor();
		wp_enqueue_style( 'smokyforms-builder', SMOKYFORMS_URL . 'assets/css/builder.css', array( 'wp-components' ), SMOKYFORMS_VERSION );
		wp_enqueue_script( 'smokyforms-calc', SMOKYFORMS_URL . 'assets/js/calc.js', array(), SMOKYFORMS_VERSION, true );
		wp_enqueue_script(
			'smokyforms-builder',
			SMOKYFORMS_URL . 'assets/js/builder.js',
			array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-url', 'wp-html-entities', 'smokyforms-calc' ),
			SMOKYFORMS_VERSION,
			true
		);
		wp_set_script_translations( 'smokyforms-builder', 'smokyforms', SMOKYFORMS_DIR . 'languages' );

		$types = array();
		foreach ( Fields::types() as $key => $t ) {
			$types[] = array(
				'type'    => $key,
				'label'   => $t['label'],
				'group'   => $t['group'],
				'input'   => ! empty( $t['input'] ),
				'numeric' => ! empty( $t['numeric'] ),
			);
		}

		global $wp_locale;
		wp_add_inline_script(
			'smokyforms-builder',
			'window.smokyformsBuilder = ' . wp_json_encode(
				array(
					'form'          => self::client_form( $form ),
					'defaults'      => Forms::default_settings(),
					'types'         => $types,
					'templates'     => Templates::all(),
					'tags'          => Notifications::tags(),
					'captchaReady'  => ! empty( PluginSettings::get( 'math' ) ) || ( 'none' !== PluginSettings::get( 'captcha' ) && '' !== PluginSettings::get( 'captcha_site_key' ) ),
					'listUrl'       => admin_url( Menu::PARENT ),
					'settingsUrl'   => admin_url( Menu::PARENT . '&page=' . Settings::SLUG ),
					'builderUrl'    => Menu::builder_url(),
					'upload'        => array(
						'extensions' => Fields::DEFAULT_EXTENSIONS,
						'maxSize'    => max( 1, floor( wp_max_upload_size() / MB_IN_BYTES ) ),
					),
					'number'        => array(
						'decimal'   => $wp_locale->number_format['decimal_point'] ?? '.',
						'thousands' => $wp_locale->number_format['thousands_sep'] ?? ',',
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Builder screen. The app renders into the container.
	 */
	public static function page() {
		if ( ! current_user_can( Menu::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'smokyforms' ) );
		}
		$id = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id && ! Forms::get( $id ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Edit Form', 'smokyforms' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'This form does not exist.', 'smokyforms' ) . '</p></div></div>';
			return;
		}
		echo '<div class="wrap smokyforms-wrap"><div id="smokyforms-builder"><p>' . esc_html__( 'Loading the form builder…', 'smokyforms' ) . '</p></div><noscript><p>' . esc_html__( 'The form builder needs JavaScript.', 'smokyforms' ) . '</p></noscript></div>';
	}
}
