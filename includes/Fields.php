<?php
/**
 * Field types: configuration, validation and values.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of the field types.
 */
final class Fields {

	/**
	 * Field types. Pro and add-ons can add types through the `smokyforms_field_types` filter.
	 *
	 * Keys: label, group (basic|choice|special|layout), input (true when the field collects a value),
	 * numeric (true when the value can be used in a calculation).
	 *
	 * @return array<string,array>
	 */
	public static function types() {
		$types = array(
			'text'        => array( 'label' => __( 'Single line text', 'smokyforms' ), 'group' => 'basic', 'input' => true, 'numeric' => true ),
			'textarea'    => array( 'label' => __( 'Paragraph text', 'smokyforms' ), 'group' => 'basic', 'input' => true, 'numeric' => false ),
			'email'       => array( 'label' => __( 'Email', 'smokyforms' ), 'group' => 'basic', 'input' => true, 'numeric' => false ),
			'phone'       => array( 'label' => __( 'Phone', 'smokyforms' ), 'group' => 'basic', 'input' => true, 'numeric' => false ),
			'url'         => array( 'label' => __( 'Website', 'smokyforms' ), 'group' => 'basic', 'input' => true, 'numeric' => false ),
			'number'      => array( 'label' => __( 'Number', 'smokyforms' ), 'group' => 'basic', 'input' => true, 'numeric' => true ),
			'select'      => array( 'label' => __( 'Dropdown', 'smokyforms' ), 'group' => 'choice', 'input' => true, 'numeric' => true ),
			'radio'       => array( 'label' => __( 'Multiple choice', 'smokyforms' ), 'group' => 'choice', 'input' => true, 'numeric' => true ),
			'checkbox'    => array( 'label' => __( 'Checkboxes', 'smokyforms' ), 'group' => 'choice', 'input' => true, 'numeric' => true ),
			'date'        => array( 'label' => __( 'Date', 'smokyforms' ), 'group' => 'special', 'input' => true, 'numeric' => true ),
			'hidden'      => array( 'label' => __( 'Hidden field', 'smokyforms' ), 'group' => 'special', 'input' => true, 'numeric' => true ),
			'consent'     => array( 'label' => __( 'Consent checkbox', 'smokyforms' ), 'group' => 'special', 'input' => true, 'numeric' => false ),
			'calculation' => array( 'label' => __( 'Calculation', 'smokyforms' ), 'group' => 'special', 'input' => true, 'numeric' => true ),
			'heading'     => array( 'label' => __( 'Section heading', 'smokyforms' ), 'group' => 'layout', 'input' => false, 'numeric' => false ),
			'html'        => array( 'label' => __( 'Text block', 'smokyforms' ), 'group' => 'layout', 'input' => false, 'numeric' => false ),
		);
		return apply_filters( 'smokyforms_field_types', $types );
	}

	/**
	 * Whether a type collects a value.
	 *
	 * @param string $type Field type.
	 * @return bool
	 */
	public static function is_input( $type ) {
		$types = self::types();
		return ! empty( $types[ $type ]['input'] );
	}

	/**
	 * Whether a type has choices.
	 *
	 * @param string $type Field type.
	 * @return bool
	 */
	public static function has_choices( $type ) {
		return in_array( $type, array( 'select', 'radio', 'checkbox' ), true );
	}

	/**
	 * Cleans one field configuration coming from the builder.
	 *
	 * @param array $f Raw field.
	 * @return array|null Clean field, or null when the type is unknown.
	 */
	public static function sanitize_config( $f ) {
		if ( ! is_array( $f ) || empty( $f['type'] ) ) {
			return null;
		}
		$type  = sanitize_key( $f['type'] );
		$types = self::types();
		if ( ! isset( $types[ $type ] ) ) {
			return null;
		}
		$id = isset( $f['id'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $f['id'] ) ) : '';
		if ( '' === $id ) {
			$id = 'f' . wp_rand( 1000, 999999 );
		}

		$out = array(
			'id'          => $id,
			'type'        => $type,
			'label'       => isset( $f['label'] ) ? sanitize_text_field( $f['label'] ) : '',
			'required'    => ! empty( $f['required'] ),
			'help'        => isset( $f['help'] ) ? sanitize_text_field( $f['help'] ) : '',
			'placeholder' => isset( $f['placeholder'] ) ? sanitize_text_field( $f['placeholder'] ) : '',
			'default'     => isset( $f['default'] ) ? sanitize_text_field( $f['default'] ) : '',
			'width'       => ( isset( $f['width'] ) && 'half' === $f['width'] ) ? 'half' : 'full',
		);

		if ( self::has_choices( $type ) ) {
			$out['options'] = array();
			$options        = isset( $f['options'] ) && is_array( $f['options'] ) ? $f['options'] : array();
			foreach ( $options as $o ) {
				if ( ! is_array( $o ) ) {
					continue;
				}
				$label = isset( $o['label'] ) ? sanitize_text_field( $o['label'] ) : '';
				if ( '' === $label ) {
					continue;
				}
				$out['options'][] = array(
					'label'    => $label,
					'calc'     => ( isset( $o['calc'] ) && is_numeric( $o['calc'] ) ) ? (float) $o['calc'] : 0,
					'selected' => ! empty( $o['selected'] ),
				);
			}
		}

		if ( 'number' === $type ) {
			foreach ( array( 'min', 'max', 'step' ) as $k ) {
				$out[ $k ] = ( isset( $f[ $k ] ) && is_numeric( $f[ $k ] ) ) ? (float) $f[ $k ] : '';
			}
		}

		if ( 'calculation' === $type ) {
			$out['formula']  = isset( $f['formula'] ) ? Formula::clean( (string) $f['formula'] ) : '';
			$out['decimals'] = isset( $f['decimals'] ) ? max( 0, min( 6, absint( $f['decimals'] ) ) ) : 2;
			$out['prefix']   = isset( $f['prefix'] ) ? sanitize_text_field( $f['prefix'] ) : '';
			$out['suffix']   = isset( $f['suffix'] ) ? sanitize_text_field( $f['suffix'] ) : '';
			$out['visible']  = ! isset( $f['visible'] ) || ! empty( $f['visible'] );
		}

		if ( 'consent' === $type ) {
			$out['text'] = isset( $f['text'] ) ? wp_kses( (string) $f['text'], array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ) : '';
		}

		if ( 'html' === $type ) {
			$out['content'] = isset( $f['content'] ) ? wp_kses_post( (string) $f['content'] ) : '';
		}

		return apply_filters( 'smokyforms_sanitize_field', $out, $f );
	}

	/**
	 * Validates and cleans a submitted value.
	 *
	 * @param array $field Field configuration.
	 * @param mixed $raw   Submitted value.
	 * @return array{0:mixed,1:string} Clean value and error message ('' when valid).
	 */
	public static function validate( $field, $raw ) {
		$type     = $field['type'];
		$required = ! empty( $field['required'] );
		$empty    = __( 'This field is required.', 'smokyforms' );

		if ( 'checkbox' === $type ) {
			$raw     = is_array( $raw ) ? $raw : ( null === $raw || '' === $raw ? array() : array( $raw ) );
			$labels  = wp_list_pluck( $field['options'], 'label' );
			$value   = array_values( array_intersect( $labels, array_map( 'wp_unslash', array_map( 'strval', $raw ) ) ) );
			$message = ( $required && ! $value ) ? $empty : '';
			return array( $value, $message );
		}

		$raw = is_scalar( $raw ) ? wp_unslash( (string) $raw ) : '';

		if ( 'consent' === $type ) {
			$value = '' !== $raw ? '1' : '';
			return array( $value, ( $required && '' === $value ) ? $empty : '' );
		}

		$value = 'textarea' === $type ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );

		if ( '' === $value ) {
			return array( '', $required ? $empty : '' );
		}

		switch ( $type ) {
			case 'email':
				if ( ! is_email( $value ) ) {
					return array( $value, __( 'Please enter a valid email address.', 'smokyforms' ) );
				}
				break;
			case 'url':
				if ( ! preg_match( '#^https?://#i', $value ) ) {
					$value = 'https://' . $value;
				}
				if ( ! wp_http_validate_url( $value ) && false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
					return array( $value, __( 'Please enter a valid website address.', 'smokyforms' ) );
				}
				$value = esc_url_raw( $value );
				break;
			case 'phone':
				if ( ! preg_match( '/^[0-9 +().\-\/]{5,25}$/', $value ) ) {
					return array( $value, __( 'Please enter a valid phone number.', 'smokyforms' ) );
				}
				break;
			case 'number':
				$num = str_replace( ',', '.', $value );
				if ( ! is_numeric( $num ) ) {
					return array( $value, __( 'Please enter a number.', 'smokyforms' ) );
				}
				$num = (float) $num;
				if ( '' !== $field['min'] && $num < (float) $field['min'] ) {
					/* translators: %s: smallest allowed number */
					return array( $value, sprintf( __( 'Please enter a number of at least %s.', 'smokyforms' ), self::number_text( $field['min'] ) ) );
				}
				if ( '' !== $field['max'] && $num > (float) $field['max'] ) {
					/* translators: %s: largest allowed number */
					return array( $value, sprintf( __( 'Please enter a number of at most %s.', 'smokyforms' ), self::number_text( $field['max'] ) ) );
				}
				$value = self::number_text( $num );
				break;
			case 'date':
				$d = \DateTime::createFromFormat( '!Y-m-d', $value );
				if ( ! $d || $d->format( 'Y-m-d' ) !== $value ) {
					return array( $value, __( 'Please enter a valid date.', 'smokyforms' ) );
				}
				break;
			case 'select':
			case 'radio':
				if ( ! in_array( $value, wp_list_pluck( $field['options'], 'label' ), true ) ) {
					return array( '', __( 'Please choose one of the options.', 'smokyforms' ) );
				}
				break;
		}
		return array( $value, '' );
	}

	/**
	 * Number of a value inside a calculation.
	 *
	 * Choices give the calculation value of the chosen option (checkboxes: the sum),
	 * dates give the number of days since 1 January 1970, everything else its numeric value.
	 *
	 * @param array $field Field configuration.
	 * @param mixed $value Clean value.
	 * @return float
	 */
	public static function numeric( $field, $value ) {
		switch ( $field['type'] ) {
			case 'select':
			case 'radio':
			case 'checkbox':
				$sum    = 0.0;
				$chosen = (array) $value;
				foreach ( $field['options'] as $o ) {
					if ( in_array( $o['label'], $chosen, true ) ) {
						$sum += (float) $o['calc'];
					}
				}
				return $sum;
			case 'date':
				$d = \DateTime::createFromFormat( '!Y-m-d', (string) $value, new \DateTimeZone( 'UTC' ) );
				return $d ? floor( $d->getTimestamp() / DAY_IN_SECONDS ) : 0.0;
			default:
				$v = str_replace( ',', '.', (string) $value );
				return is_numeric( $v ) ? (float) $v : 0.0;
		}
	}

	/**
	 * Text of a value for e-mails.
	 *
	 * @param array $field Field configuration.
	 * @param mixed $value Clean value.
	 * @return string
	 */
	public static function display( $field, $value ) {
		if ( is_array( $value ) ) {
			return implode( ', ', $value );
		}
		if ( 'consent' === $field['type'] ) {
			return '1' === $value ? __( 'Yes', 'smokyforms' ) : __( 'No', 'smokyforms' );
		}
		if ( 'calculation' === $field['type'] && '' !== $value ) {
			return self::format_result( $field, (float) $value );
		}
		if ( 'date' === $field['type'] && '' !== $value ) {
			$ts = strtotime( $value . ' 00:00:00 UTC' );
			return $ts ? wp_date( get_option( 'date_format' ), $ts, new \DateTimeZone( 'UTC' ) ) : $value;
		}
		return (string) $value;
	}

	/**
	 * Formatted calculation result with prefix and suffix, in the site's number format.
	 *
	 * @param array $field  Calculation field.
	 * @param float $number Result.
	 * @return string
	 */
	public static function format_result( $field, $number ) {
		return trim( $field['prefix'] . number_format_i18n( $number, (int) $field['decimals'] ) . $field['suffix'] );
	}

	/**
	 * Number without trailing zeros.
	 *
	 * @param float|string $n Number.
	 * @return string
	 */
	public static function number_text( $n ) {
		$s = rtrim( rtrim( sprintf( '%.10F', (float) $n ), '0' ), '.' );
		return '-0' === $s ? '0' : $s;
	}
}
