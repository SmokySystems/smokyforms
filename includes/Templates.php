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
	 * Conditional logic with one rule: show the field when the rule matches.
	 *
	 * @param string $field Field id.
	 * @param string $op    Operator.
	 * @param string $value Value.
	 * @return array
	 */
	private static function when( $field, $op, $value ) {
		return array(
			'enabled' => true,
			'action'  => 'show',
			'match'   => 'all',
			'rules'   => array(
				array(
					'field' => $field,
					'op'    => $op,
					'value' => $value,
				),
			),
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
			array(
				'key'         => 'feedback',
				'name'        => __( 'Customer feedback', 'smokyforms' ),
				'description' => __( 'Star rating with follow-up questions that depend on the rating.', 'smokyforms' ),
				'calc'        => false,
				'title'       => __( 'Customer feedback', 'smokyforms' ),
				'fields'      => array(
					self::f( 'rating', 'rating', __( 'How would you rate your experience?', 'smokyforms' ), array( 'required' => true, 'max' => 5 ) ),
					self::f( 'liked', 'textarea', __( 'What did you like most?', 'smokyforms' ), array( 'logic' => self::when( 'rating', 'gt', '3' ) ) ),
					self::f( 'improve', 'textarea', __( 'What should we do better?', 'smokyforms' ), array( 'logic' => self::when( 'rating', 'lt', '4' ) ) ),
					self::f( 'recommend', 'radio', __( 'Would you recommend us to a friend?', 'smokyforms' ), array( 'options' => self::options( array( __( 'Yes', 'smokyforms' ) => 0, __( 'No', 'smokyforms' ) => 0 ) ) ) ),
					self::f( 'name', 'text', __( 'Name', 'smokyforms' ), array( 'width' => 'half' ) ),
					self::f( 'email', 'email', __( 'Email', 'smokyforms' ), array( 'width' => 'half' ) ),
				),
				'settings'    => array(
					'success_message' => __( 'Thank you for your feedback!', 'smokyforms' ),
				),
			),
			array(
				'key'         => 'application',
				'name'        => __( 'Job application', 'smokyforms' ),
				'description' => __( 'Two pages with a CV upload.', 'smokyforms' ),
				'calc'        => false,
				'title'       => __( 'Job application', 'smokyforms' ),
				'fields'      => array(
					$name,
					self::f( 'email', 'email', __( 'Email', 'smokyforms' ), array( 'required' => true, 'width' => 'half' ) ),
					self::f( 'phone', 'phone', __( 'Phone', 'smokyforms' ), array( 'width' => 'half' ) ),
					self::f( 'step2', 'page', __( 'Your application', 'smokyforms' ), array( 'prev_label' => '', 'next_label' => '' ) ),
					self::f( 'position', 'text', __( 'Position you are applying for', 'smokyforms' ), array( 'required' => true ) ),
					self::f( 'cv', 'file', __( 'Your CV', 'smokyforms' ), array( 'required' => true, 'extensions' => 'pdf, doc, docx, odt', 'max_size' => 5, 'max_files' => 1, 'attach' => true ) ),
					self::f( 'letter', 'textarea', __( 'Cover letter', 'smokyforms' ) ),
				),
				'settings'    => array_merge( $confirm, array( 'success_message' => __( 'Thank you! We received your application.', 'smokyforms' ) ) ),
			),
			array(
				'key'         => 'event',
				'name'        => __( 'Event registration', 'smokyforms' ),
				'description' => __( 'Register several people at once; the total follows the tickets.', 'smokyforms' ),
				'calc'        => true,
				'title'       => __( 'Event registration', 'smokyforms' ),
				'fields'      => array(
					$name,
					$email,
					self::f(
						'attendees',
						'repeater',
						__( 'Attendees', 'smokyforms' ),
						array(
							'required'  => true,
							'min_rows'  => 1,
							'max_rows'  => 10,
							'add_label' => __( 'Add a person', 'smokyforms' ),
							'subfields' => array(
								self::f( 'person', 'text', __( 'Full name', 'smokyforms' ), array( 'required' => true, 'width' => 'half' ) ),
								self::f( 'ticket', 'select', __( 'Ticket', 'smokyforms' ), array( 'required' => true, 'width' => 'half', 'options' => self::options( array( __( 'Adult – 25', 'smokyforms' ) => 25, __( 'Child – 10', 'smokyforms' ) => 10 ) ) ) ),
							),
						)
					),
					self::f( 'total', 'calculation', __( 'Total', 'smokyforms' ), array( 'formula' => '{attendees.ticket}', 'decimals' => 2, 'prefix' => '', 'suffix' => '', 'visible' => true ) ),
				),
				'settings'    => $confirm,
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
