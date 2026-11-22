<?php
/**
 * Form storage.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms;

defined( 'ABSPATH' ) || exit;

/**
 * Forms are posts of the type `smokyforms_form`; the form itself is JSON in the `_smokyforms_form` meta.
 */
final class Forms {

	const POST_TYPE = 'smokyforms_form';
	const META      = '_smokyforms_form';

	/**
	 * Registers the post type.
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Forms', 'smokyforms' ),
					'singular_name'      => __( 'Form', 'smokyforms' ),
					'add_new'            => __( 'Add New Form', 'smokyforms' ),
					'add_new_item'       => __( 'Add New Form', 'smokyforms' ),
					'edit_item'          => __( 'Edit Form', 'smokyforms' ),
					'all_items'          => __( 'All Forms', 'smokyforms' ),
					'search_items'       => __( 'Search Forms', 'smokyforms' ),
					'not_found'          => __( 'No forms yet.', 'smokyforms' ),
					'not_found_in_trash' => __( 'No forms in the Trash.', 'smokyforms' ),
					'menu_name'          => 'SmokyForms',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	/**
	 * Settings of a new form.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'submit_label'    => __( 'Submit', 'smokyforms' ),
			'success_message' => __( 'Thank you! Your message has been sent.', 'smokyforms' ),
			'redirect'        => '',
			'notify'          => array(
				'enabled' => true,
				'to'      => '{admin_email}',
				'subject' => __( 'New entry: {form_title}', 'smokyforms' ),
				'message' => '{all_fields}',
			),
			'confirm'         => array(
				'enabled' => false,
				'field'   => '',
				'subject' => __( 'Thank you for your message', 'smokyforms' ),
				'message' => __( "Hello,\n\nthank you for contacting us. We received your message and will reply soon.\n\n{all_fields}", 'smokyforms' ),
			),
			'captcha'         => true,
		);
	}

	/**
	 * A form, cleaned and complete.
	 *
	 * @param int $id Post id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}
		$data = json_decode( (string) get_post_meta( $post->ID, self::META, true ), true );
		$data = is_array( $data ) ? $data : array();
		$form = self::sanitize( $data );

		$form['id']     = $post->ID;
		$form['title']  = $post->post_title;
		$form['status'] = $post->post_status;
		return $form;
	}

	/**
	 * Saves a form.
	 *
	 * @param int    $id    Post id (0 for a new form).
	 * @param string $title Title.
	 * @param array  $data  Fields, tables and settings.
	 * @return int|\WP_Error Post id.
	 */
	public static function save( $id, $title, $data ) {
		$form = self::sanitize( $data );
		$post = array(
			'post_type'   => self::POST_TYPE,
			'post_title'  => sanitize_text_field( $title ),
			'post_status' => 'publish',
		);
		if ( $id ) {
			$post['ID'] = (int) $id;
			$result     = wp_update_post( $post, true );
		} else {
			$result = wp_insert_post( $post, true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		update_post_meta( $result, self::META, wp_slash( wp_json_encode( $form ) ) );
		do_action( 'smokyforms_form_saved', $result, $form );
		return $result;
	}

	/**
	 * Cleans a form definition.
	 *
	 * @param array $data Raw form.
	 * @return array
	 */
	public static function sanitize( $data ) {
		$fields = array();
		$seen   = array();
		foreach ( (array) ( $data['fields'] ?? array() ) as $raw ) {
			$f = Fields::sanitize_config( $raw );
			if ( ! $f ) {
				continue;
			}
			while ( isset( $seen[ $f['id'] ] ) ) {
				$f['id'] .= 'x';
			}
			$seen[ $f['id'] ] = true;
			$fields[]         = $f;
		}

		$tables = array();
		foreach ( (array) ( $data['tables'] ?? array() ) as $t ) {
			$t = self::sanitize_table( $t );
			if ( $t ) {
				$tables[] = $t;
			}
		}

		$defaults = self::default_settings();
		$s        = is_array( $data['settings'] ?? null ) ? $data['settings'] : array();
		$settings = array(
			'submit_label'    => isset( $s['submit_label'] ) && '' !== trim( $s['submit_label'] ) ? sanitize_text_field( $s['submit_label'] ) : $defaults['submit_label'],
			'success_message' => isset( $s['success_message'] ) ? wp_kses_post( $s['success_message'] ) : $defaults['success_message'],
			'redirect'        => isset( $s['redirect'] ) ? esc_url_raw( $s['redirect'] ) : '',
			'notify'          => array(
				'enabled' => isset( $s['notify']['enabled'] ) ? ! empty( $s['notify']['enabled'] ) : true,
				'to'      => isset( $s['notify']['to'] ) ? sanitize_text_field( $s['notify']['to'] ) : $defaults['notify']['to'],
				'subject' => isset( $s['notify']['subject'] ) ? sanitize_text_field( $s['notify']['subject'] ) : $defaults['notify']['subject'],
				'message' => isset( $s['notify']['message'] ) ? sanitize_textarea_field( $s['notify']['message'] ) : $defaults['notify']['message'],
			),
			'confirm'         => array(
				'enabled' => ! empty( $s['confirm']['enabled'] ),
				'field'   => isset( $s['confirm']['field'] ) ? sanitize_key( $s['confirm']['field'] ) : '',
				'subject' => isset( $s['confirm']['subject'] ) ? sanitize_text_field( $s['confirm']['subject'] ) : $defaults['confirm']['subject'],
				'message' => isset( $s['confirm']['message'] ) ? sanitize_textarea_field( $s['confirm']['message'] ) : $defaults['confirm']['message'],
			),
			'captcha'         => isset( $s['captcha'] ) ? ! empty( $s['captcha'] ) : true,
		);
		if ( '' === trim( $settings['notify']['message'] ) ) {
			$settings['notify']['message'] = '{all_fields}';
		}

		return apply_filters(
			'smokyforms_sanitize_form',
			array(
				'version'  => 1,
				'fields'   => $fields,
				'tables'   => $tables,
				'settings' => $settings,
			),
			$data
		);
	}

	/**
	 * Cleans a price table.
	 *
	 * @param array $t Raw table.
	 * @return array|null
	 */
	private static function sanitize_table( $t ) {
		if ( ! is_array( $t ) ) {
			return null;
		}
		$id = isset( $t['id'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $t['id'] ) ) : '';
		if ( '' === $id || ! ctype_alpha( $id[0] ) ) {
			return null;
		}
		$rows  = array_values( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $t['rows'] ?? array() ) ) ) );
		$cols  = array_values( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $t['cols'] ?? array() ) ) ) );
		$cells = array();
		foreach ( $rows as $r => $unused ) {
			$cells[ $r ] = array();
			$width       = max( 1, count( $cols ) );
			for ( $c = 0; $c < $width; $c++ ) {
				$v               = $t['cells'][ $r ][ $c ] ?? '';
				$cells[ $r ][ $c ] = is_numeric( $v ) ? (float) $v : 0;
			}
		}
		return array(
			'id'    => $id,
			'name'  => isset( $t['name'] ) ? sanitize_text_field( $t['name'] ) : $id,
			'rows'  => $rows,
			'cols'  => $cols,
			'cells' => $cells,
		);
	}

	/**
	 * Field by id.
	 *
	 * @param array  $form Form.
	 * @param string $id   Field id.
	 * @return array|null
	 */
	public static function field( $form, $id ) {
		foreach ( $form['fields'] as $f ) {
			if ( $f['id'] === $id ) {
				return $f;
			}
		}
		return null;
	}

	/**
	 * Computes every calculation field from the submitted values.
	 *
	 * Calculations may use other calculations; they are evaluated in dependency order,
	 * and a calculation that depends on itself gives 0.
	 *
	 * @param array $form   Form.
	 * @param array $values Clean values by field id (calculation values are replaced).
	 * @return array Values with the calculation results.
	 */
	public static function calculate( $form, $values ) {
		$by_id = array();
		foreach ( $form['fields'] as $f ) {
			$by_id[ $f['id'] ] = $f;
		}
		$done  = array();
		$stack = array();

		$num = null;
		$num = static function ( $id ) use ( &$num, &$values, &$done, &$stack, $by_id, $form ) {
			if ( ! isset( $by_id[ $id ] ) ) {
				return 0.0;
			}
			$f = $by_id[ $id ];
			if ( 'calculation' !== $f['type'] ) {
				return Fields::numeric( $f, $values[ $id ] ?? '' );
			}
			if ( isset( $done[ $id ] ) ) {
				return $done[ $id ];
			}
			if ( isset( $stack[ $id ] ) ) {
				return 0.0; // Circular reference.
			}
			$stack[ $id ] = true;
			$text         = static function ( $ref ) use ( &$values ) {
				$v = $values[ $ref ] ?? '';
				return is_array( $v ) ? (string) reset( $v ) : (string) $v;
			};
			$result       = Formula::evaluate( $f['formula'], $num, $text, $form['tables'] );
			unset( $stack[ $id ] );
			$done[ $id ] = $result;
			return $result;
		};

		foreach ( $form['fields'] as $f ) {
			if ( 'calculation' === $f['type'] ) {
				$values[ $f['id'] ] = Fields::number_text( round( $num( $f['id'] ), (int) $f['decimals'] ) );
			}
		}
		return $values;
	}
}
