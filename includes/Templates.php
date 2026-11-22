<?php
/**
 * Ready-made forms.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms;

defined( 'ABSPATH' ) || exit;

/**
 * Templates offered when a new form is created. All of them, calculators included, are free.
 */
final class Templates {

	/**
	 * Option list helper.
	 *
	 * @param array $pairs label => calculation value.
	 * @return array
	 */
	private static function options( $pairs ) {
		$out = array();
		foreach ( $pairs as $label => $calc ) {
			$out[] = array(
				'label'    => (string) $label,
				'calc'     => $calc,
				'selected' => false,
			);
		}
		return $out;
	}

	/**
	 * Field helper.
	 *
	 * @param string $id    Field id.
	 * @param string $type  Type.
	 * @param string $label Label.
	 * @param array  $extra Other settings.
	 * @return array
	 */
	private static function f( $id, $type, $label, $extra = array() ) {
		return array_merge(
			array(
				'id'          => $id,
				'type'        => $type,
				'label'       => $label,
				'required'    => false,
				'help'        => '',
				'placeholder' => '',
				'default'     => '',
				'width'       => 'full',
			),
			$extra
		);
	}

	/**
	 * All templates.
	 *
	 * @return array<int,array> key, name, description, calc (bool), title, fields, tables, settings
	 */
	public static function all() {
		$name    = self::f( 'name', 'text', __( 'Name', 'smokyforms' ), array( 'required' => true ) );
		$email   = self::f( 'email', 'email', __( 'Email', 'smokyforms' ), array( 'required' => true ) );
		$phone   = self::f( 'phone', 'phone', __( 'Phone', 'smokyforms' ) );
		$message = self::f( 'message', 'textarea', __( 'Message', 'smokyforms' ), array( 'required' => true ) );
		$confirm = array(
			'confirm' => array(
				'enabled' => true,
				'field'   => 'email',
				'subject' => __( 'Thank you for your message', 'smokyforms' ),
				'message' => __( "Hello,\n\nthank you for contacting us. We received your message and will reply soon.\n\n{all_fields}", 'smokyforms' ),
			),
		);

		$templates = array(
			array(
				'key'         => 'blank',
				'name'        => __( 'Blank form', 'smokyforms' ),
				'description' => __( 'Start from an empty form.', 'smokyforms' ),
				'calc'        => false,
				'title'       => __( 'Untitled form', 'smokyforms' ),
				'fields'      => array(),
			),
			array(
				'key'         => 'contact',
				'name'        => __( 'Contact form', 'smokyforms' ),
				'description' => __( 'Name, email, phone and message.', 'smokyforms' ),
				'calc'        => false,
				'title'       => __( 'Contact form', 'smokyforms' ),
				'fields'      => array(
					array_merge( $name, array( 'width' => 'half' ) ),
					array_merge( $email, array( 'width' => 'half' ) ),
					$phone,
					$message,
				),
				'settings'    => $confirm,
			),
			array(
				'key'         => 'quote',
				'name'        => __( 'Quote request', 'smokyforms' ),
				'description' => __( 'Service, extras and size give an estimated price.', 'smokyforms' ),
				'calc'        => true,
				'title'       => __( 'Quote request', 'smokyforms' ),
				'fields'      => array(
					self::f(
						'service',
						'radio',
						__( 'Service', 'smokyforms' ),
						array(
							'required' => true,
							'options'  => self::options(
								array(
									__( 'Basic cleaning', 'smokyforms' ) => 2,
									__( 'Deep cleaning', 'smokyforms' )  => 3.5,
								)
							),
						)
					),
					self::f( 'size', 'number', __( 'Size (m²)', 'smokyforms' ), array( 'required' => true, 'min' => 1, 'max' => '', 'step' => '' ) ),
					self::f(
						'extras',
						'checkbox',
						__( 'Extras', 'smokyforms' ),
						array(
							'options' => self::options(
								array(
									__( 'Windows', 'smokyforms' ) => 40,
									__( 'Balcony', 'smokyforms' ) => 25,
									__( 'Oven', 'smokyforms' )    => 30,
								)
							),
						)
					),
					self::f(
						'total',
						'calculation',
						__( 'Estimated price', 'smokyforms' ),
						array(
							'formula'  => '{service} * {size} + {extras}',
							'decimals' => 2,
							'prefix'   => '',
							'suffix'   => '',
							'visible'  => true,
						)
					),
					array_merge( $name, array( 'width' => 'half' ) ),
					array_merge( $email, array( 'width' => 'half' ) ),
				),
				'settings'    => $confirm,
			),
			array(
				'key'         => 'order',
				'name'        => __( 'Order form', 'smokyforms' ),
				'description' => __( 'Products with quantities, delivery and a discount over an amount.', 'smokyforms' ),
				'calc'        => true,
				'title'       => __( 'Order form', 'smokyforms' ),
				'fields'      => array(
					self::f( 'qty_a', 'number', __( 'Product A – 12.00 each', 'smokyforms' ), array( 'width' => 'half', 'default' => '0', 'min' => 0, 'max' => '', 'step' => 1 ) ),
					self::f( 'qty_b', 'number', __( 'Product B – 18.50 each', 'smokyforms' ), array( 'width' => 'half', 'default' => '0', 'min' => 0, 'max' => '', 'step' => 1 ) ),
					self::f(
						'delivery',
						'select',
						__( 'Delivery', 'smokyforms' ),
						array(
							'required' => true,
							'options'  => self::options(
								array(
									__( 'Pick up', 'smokyforms' )  => 0,
									__( 'Standard', 'smokyforms' ) => 5,
									__( 'Express', 'smokyforms' )  => 12,
								)
							),
						)
					),
					self::f( 'subtotal', 'calculation', __( 'Subtotal', 'smokyforms' ), array( 'formula' => '{qty_a} * 12 + {qty_b} * 18.5', 'decimals' => 2, 'prefix' => '', 'suffix' => '', 'visible' => true ) ),
					self::f( 'discount', 'calculation', __( 'Discount (10% over 100)', 'smokyforms' ), array( 'formula' => 'if({subtotal} > 100, {subtotal} * 0.1, 0)', 'decimals' => 2, 'prefix' => '', 'suffix' => '', 'visible' => true ) ),
					self::f( 'total', 'calculation', __( 'Total', 'smokyforms' ), array( 'formula' => '{subtotal} - {discount} + {delivery}', 'decimals' => 2, 'prefix' => '', 'suffix' => '', 'visible' => true ) ),
					array_merge( $name, array( 'width' => 'half' ) ),
					array_merge( $email, array( 'width' => 'half' ) ),
				),
				'settings'    => $confirm,
			),
			array(
				'key'         => 'booking',
				'name'        => __( 'Booking with nights', 'smokyforms' ),
				'description' => __( 'Arrival and departure dates give the number of nights and the price.', 'smokyforms' ),
				'calc'        => true,
				'title'       => __( 'Booking request', 'smokyforms' ),
				'fields'      => array(
					self::f( 'arrival', 'date', __( 'Arrival', 'smokyforms' ), array( 'required' => true, 'width' => 'half' ) ),
					self::f( 'departure', 'date', __( 'Departure', 'smokyforms' ), array( 'required' => true, 'width' => 'half' ) ),
					self::f(
						'room',
						'radio',
						__( 'Room', 'smokyforms' ),
						array(
							'required' => true,
							'options'  => self::options(
								array(
									__( 'Standard – 80 per night', 'smokyforms' ) => 80,
									__( 'Suite – 140 per night', 'smokyforms' )   => 140,
								)
							),
						)
					),
					self::f( 'nights', 'calculation', __( 'Nights', 'smokyforms' ), array( 'formula' => 'max(days({arrival}, {departure}), 0)', 'decimals' => 0, 'prefix' => '', 'suffix' => '', 'visible' => true ) ),
					self::f( 'price', 'calculation', __( 'Price', 'smokyforms' ), array( 'formula' => '{nights} * {room}', 'decimals' => 2, 'prefix' => '', 'suffix' => '', 'visible' => true ) ),
					array_merge( $name, array( 'width' => 'half' ) ),
					array_merge( $email, array( 'width' => 'half' ) ),
				),
				'settings'    => $confirm,
			),
			array(
				'key'         => 'shipping',
				'name'        => __( 'Price table', 'smokyforms' ),
				'description' => __( 'Shipping price from a table of zones and parcel sizes.', 'smokyforms' ),
				'calc'        => true,
				'title'       => __( 'Shipping price', 'smokyforms' ),
				'fields'      => array(
					self::f(
						'zone',
						'select',
						__( 'Zone', 'smokyforms' ),
						array(
							'required' => true,
							'width'    => 'half',
							'options'  => self::options(
								array(
									__( 'Zone 1', 'smokyforms' ) => 0,
									__( 'Zone 2', 'smokyforms' ) => 0,
									__( 'Zone 3', 'smokyforms' ) => 0,
								)
							),
						)
					),
					self::f(
						'parcel',
						'select',
						__( 'Parcel', 'smokyforms' ),
						array(
							'required' => true,
							'width'    => 'half',
							'options'  => self::options(
								array(
									__( 'Small', 'smokyforms' )  => 0,
									__( 'Medium', 'smokyforms' ) => 0,
									__( 'Large', 'smokyforms' )  => 0,
								)
							),
						)
					),
					self::f( 'cost', 'calculation', __( 'Shipping price', 'smokyforms' ), array( 'formula' => 'lookup(prices, {zone}, {parcel})', 'decimals' => 2, 'prefix' => '', 'suffix' => '', 'visible' => true ) ),
					$email,
				),
				'tables'      => array(
					array(
						'id'    => 'prices',
						'name'  => __( 'Shipping prices', 'smokyforms' ),
						'rows'  => array( __( 'Zone 1', 'smokyforms' ), __( 'Zone 2', 'smokyforms' ), __( 'Zone 3', 'smokyforms' ) ),
						'cols'  => array( __( 'Small', 'smokyforms' ), __( 'Medium', 'smokyforms' ), __( 'Large', 'smokyforms' ) ),
						'cells' => array( array( 5, 8, 12 ), array( 7, 11, 16 ), array( 10, 15, 22 ) ),
					),
				),
			),
			array(
				'key'         => 'bmi',
				'name'        => __( 'BMI calculator', 'smokyforms' ),
				'description' => __( 'Body mass index from height and weight.', 'smokyforms' ),
				'calc'        => true,
				'title'       => __( 'BMI calculator', 'smokyforms' ),
				'fields'      => array(
					self::f( 'height', 'number', __( 'Height (cm)', 'smokyforms' ), array( 'required' => true, 'width' => 'half', 'min' => 50, 'max' => 250, 'step' => '' ) ),
					self::f( 'weight', 'number', __( 'Weight (kg)', 'smokyforms' ), array( 'required' => true, 'width' => 'half', 'min' => 10, 'max' => 400, 'step' => '' ) ),
					self::f( 'bmi', 'calculation', __( 'Your BMI', 'smokyforms' ), array( 'formula' => 'if({height} > 0, {weight} / (({height} / 100) ^ 2), 0)', 'decimals' => 1, 'prefix' => '', 'suffix' => '', 'visible' => true ) ),
				),
				'settings'    => array(
					'notify'       => array( 'enabled' => false ),
					'submit_label' => __( 'Send', 'smokyforms' ),
				),
			),
			array(
				'key'         => 'loan',
				'name'        => __( 'Loan calculator', 'smokyforms' ),
				'description' => __( 'Monthly payment from amount, interest rate and term.', 'smokyforms' ),
				'calc'        => true,
				'title'       => __( 'Loan calculator', 'smokyforms' ),
				'fields'      => array(
					self::f( 'amount', 'number', __( 'Loan amount', 'smokyforms' ), array( 'required' => true, 'default' => '10000', 'min' => 0, 'max' => '', 'step' => '' ) ),
					self::f( 'rate', 'number', __( 'Yearly interest rate (%)', 'smokyforms' ), array( 'required' => true, 'width' => 'half', 'default' => '5', 'min' => 0, 'max' => 100, 'step' => '' ) ),
					self::f( 'months', 'number', __( 'Term (months)', 'smokyforms' ), array( 'required' => true, 'width' => 'half', 'default' => '36', 'min' => 1, 'max' => 600, 'step' => 1 ) ),
					self::f( 'monthly', 'calculation', __( 'Monthly payment', 'smokyforms' ), array( 'formula' => 'if({rate} > 0, {amount} * ({rate} / 1200) / (1 - (1 + {rate} / 1200) ^ (-{months})), {amount} / max({months}, 1))', 'decimals' => 2, 'prefix' => '', 'suffix' => '', 'visible' => true ) ),
				),
				'settings'    => array(
					'notify'       => array( 'enabled' => false ),
					'submit_label' => __( 'Send', 'smokyforms' ),
				),
			),
		);

		foreach ( $templates as &$t ) {
			$t['tables']   = $t['tables'] ?? array();
			$settings      = array_replace_recursive( Forms::default_settings(), $t['settings'] ?? array() );
			$t['settings'] = $settings;
		}
		return apply_filters( 'smokyforms_templates', $templates );
	}
}
