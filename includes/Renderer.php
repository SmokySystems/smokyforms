<?php
/**
 * Front-end output: shortcode, block and form HTML.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms;

defined( 'ABSPATH' ) || exit;

/**
 * Renders forms. Assets load only on pages that show a form, and the calculation
 * script only for forms with a calculation field.
 */
final class Renderer {

	/**
	 * Number of forms rendered on this page (for unique element ids).
	 *
	 * @var int
	 */
	private static $count = 0;

	/**
	 * Registers the shortcode, the block and the assets.
	 */
	public static function register() {
		add_shortcode( 'smokyforms', array( __CLASS__, 'shortcode' ) );

		wp_register_style( 'smokyforms', SMOKYFORMS_URL . 'assets/css/form.css', array(), SMOKYFORMS_VERSION );
		wp_register_script( 'smokyforms-calc', SMOKYFORMS_URL . 'assets/js/calc.js', array(), SMOKYFORMS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_register_script( 'smokyforms', SMOKYFORMS_URL . 'assets/js/form.js', array(), SMOKYFORMS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );

		wp_register_script(
			'smokyforms-block',
			SMOKYFORMS_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-server-side-render', 'wp-api-fetch' ),
			SMOKYFORMS_VERSION,
			true
		);
		wp_set_script_translations( 'smokyforms-block', 'smokyforms', SMOKYFORMS_DIR . 'languages' );

		register_block_type(
			SMOKYFORMS_DIR . 'blocks/form',
			array(
				'render_callback' => array( __CLASS__, 'block' ),
				'editor_script'   => 'smokyforms-block',
			)
		);
	}

	/**
	 * Shortcode [smokyforms id="12"].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'smokyforms' );
		return self::render( (int) $atts['id'] );
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function block( $attributes ) {
		return self::render( isset( $attributes['formId'] ) ? (int) $attributes['formId'] : 0 );
	}

	/**
	 * HTML of a form.
	 *
	 * @param int $id Form id.
	 * @return string
	 */
	public static function render( $id ) {
		$form = Forms::get( $id );
		if ( ! $form || 'publish' !== $form['status'] ) {
			return current_user_can( 'edit_posts' ) ? '<p>' . esc_html__( 'SmokyForms: this form does not exist.', 'smokyforms' ) . '</p>' : '';
		}
		self::$count++;
		$uid      = 'smokyforms-' . $form['id'] . '-' . self::$count;
		$has_calc = false;
		foreach ( $form['fields'] as $f ) {
			if ( 'calculation' === $f['type'] ) {
				$has_calc = true;
			}
		}

		wp_enqueue_style( 'smokyforms' );
		if ( $has_calc ) {
			wp_enqueue_script( 'smokyforms-calc' );
		}
		wp_enqueue_script( 'smokyforms' );
		self::localize();

		$captcha = self::captcha( $form );

		ob_start();
		// Message after a submission without JavaScript (see Submission::fallback()).
		$sent = isset( $_GET['smokyforms_sent'] ) ? absint( $_GET['smokyforms_sent'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$fail = isset( $_GET['smokyforms_error'] ) ? absint( $_GET['smokyforms_error'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<form id="<?php echo esc_attr( $uid ); ?>" class="smokyforms" method="post" novalidate
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			data-endpoint="<?php echo esc_url( rest_url( 'smokyforms/v1/submit/' . $form['id'] ) ); ?>"
			<?php if ( $has_calc ) : ?>
			data-calc="<?php echo esc_attr( wp_json_encode( self::calc_config( $form ) ) ); ?>"
			<?php endif; ?>
			<?php if ( $form['settings']['redirect'] ) : ?>
			data-redirect="<?php echo esc_url( $form['settings']['redirect'] ); ?>"
			<?php endif; ?>
		>
			<input type="hidden" name="action" value="smokyforms_submit">
			<input type="hidden" name="smokyforms_id" value="<?php echo esc_attr( (string) $form['id'] ); ?>">
			<input type="hidden" name="smokyforms_t" value="<?php echo esc_attr( Submission::time_token() ); ?>">
			<div class="smokyforms-fields">
				<?php
				foreach ( $form['fields'] as $f ) {
					echo self::field( $f, $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in field().
				}
				?>
			</div>
			<div class="smokyforms-hp" aria-hidden="true">
				<label><?php esc_html_e( 'Leave this field empty', 'smokyforms' ); ?>
					<input type="text" name="smokyforms_website" value="" tabindex="-1" autocomplete="off">
				</label>
			</div>
			<?php echo $captcha; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
			<div class="smokyforms-submit">
				<button type="submit" class="smokyforms-button wp-element-button"><?php echo esc_html( $form['settings']['submit_label'] ); ?></button>
			</div>
			<div class="smokyforms-message" role="status" aria-live="polite"
				<?php echo ( $sent === $form['id'] || $fail === $form['id'] ) ? '' : 'hidden'; ?>
				<?php echo $fail === $form['id'] ? 'data-state="error"' : ( $sent === $form['id'] ? 'data-state="success"' : '' ); ?>>
				<?php
				if ( $sent === $form['id'] ) {
					echo wp_kses_post( wpautop( $form['settings']['success_message'] ) );
				} elseif ( $fail === $form['id'] ) {
					echo '<p>' . esc_html__( 'Please check the form and try again.', 'smokyforms' ) . '</p>';
				}
				?>
			</div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * HTML of one field.
	 *
	 * @param array  $f   Field.
	 * @param string $uid Form element id.
	 * @return string
	 */
	private static function field( $f, $uid ) {
		$type  = $f['type'];
		$id    = $uid . '-' . $f['id'];
		$name  = 'smokyforms[' . $f['id'] . ']';
		$req   = ! empty( $f['required'] );
		$star  = $req ? ' <span class="smokyforms-required" aria-hidden="true">*</span>' : '';
		$help  = '' !== $f['help'] ? '<p class="smokyforms-help" id="' . esc_attr( $id ) . '-help">' . esc_html( $f['help'] ) . '</p>' : '';
		$descr = trim( ( '' !== $f['help'] ? $id . '-help ' : '' ) . $id . '-error' );
		$aria  = ' aria-describedby="' . esc_attr( $descr ) . '"' . ( $req ? ' aria-required="true"' : '' );
		$error = '<p class="smokyforms-error" id="' . esc_attr( $id ) . '-error" hidden></p>';
		$label = '<label class="smokyforms-label" for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . $star . '</label>';
		$ph    = '' !== $f['placeholder'] ? ' placeholder="' . esc_attr( $f['placeholder'] ) . '"' : '';
		$value = $f['default'];
		$inner = '';

		switch ( $type ) {
			case 'heading':
				return '<div class="smokyforms-field smokyforms-type-heading"><h3 class="smokyforms-heading">' . esc_html( $f['label'] ) . '</h3>' . $help . '</div>';

			case 'html':
				return '<div class="smokyforms-field smokyforms-type-html">' . wp_kses_post( wpautop( $f['content'] ) ) . '</div>';

			case 'hidden':
				return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" data-field="' . esc_attr( $f['id'] ) . '">';

			case 'textarea':
				$inner = $label . '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="5"' . $ph . $aria . ( $req ? ' required' : '' ) . '>' . esc_textarea( $value ) . '</textarea>';
				break;

			case 'select':
				$opts = '<option value="">' . esc_html( '' !== $f['placeholder'] ? $f['placeholder'] : __( 'Choose…', 'smokyforms' ) ) . '</option>';
				foreach ( $f['options'] as $o ) {
					$opts .= '<option value="' . esc_attr( $o['label'] ) . '"' . selected( ! empty( $o['selected'] ), true, false ) . '>' . esc_html( $o['label'] ) . '</option>';
				}
				$inner = $label . '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $aria . ( $req ? ' required' : '' ) . '>' . $opts . '</select>';
				break;

			case 'radio':
			case 'checkbox':
				$items = '';
				foreach ( $f['options'] as $i => $o ) {
					$oid    = $id . '-' . $i;
					$items .= '<label class="smokyforms-choice" for="' . esc_attr( $oid ) . '"><input type="' . $type . '" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $name . ( 'checkbox' === $type ? '[]' : '' ) ) . '" value="' . esc_attr( $o['label'] ) . '"' . checked( ! empty( $o['selected'] ), true, false ) . '> <span>' . esc_html( $o['label'] ) . '</span></label>';
				}
				$inner = '<fieldset class="smokyforms-choices" id="' . esc_attr( $id ) . '"' . $aria . '><legend class="smokyforms-label">' . esc_html( $f['label'] ) . $star . '</legend>' . $items . '</fieldset>';
				break;

			case 'consent':
				$text  = '' !== $f['text'] ? $f['text'] : esc_html( $f['label'] );
				$inner = '<label class="smokyforms-choice" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . $aria . ( $req ? ' required' : '' ) . '> <span>' . wp_kses( $text, array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ) . $star . '</span></label>';
				break;

			case 'calculation':
				$hidden = empty( $f['visible'] ) ? ' hidden' : '';
				$inner  = '<span class="smokyforms-label" id="' . esc_attr( $id ) . '-label">' . esc_html( $f['label'] ) . '</span>'
					. '<output class="smokyforms-result" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id ) . '-label" aria-live="polite" data-result="' . esc_attr( $f['id'] ) . '">' . esc_html( Fields::format_result( $f, 0 ) ) . '</output>'
					. '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
				return '<div class="smokyforms-field smokyforms-type-calculation smokyforms-width-' . esc_attr( $f['width'] ) . '" data-field="' . esc_attr( $f['id'] ) . '"' . $hidden . '>' . $inner . $help . '</div>';

			default:
				$input_types = array(
					'text'   => 'text',
					'email'  => 'email',
					'phone'  => 'tel',
					'url'    => 'url',
					'number' => 'number',
					'date'   => 'date',
				);
				$html_type   = $input_types[ $type ] ?? 'text';
				$extra       = '';
				if ( 'number' === $type ) {
					foreach ( array( 'min', 'max', 'step' ) as $k ) {
						if ( '' !== $f[ $k ] ) {
							$extra .= ' ' . $k . '="' . esc_attr( Fields::number_text( $f[ $k ] ) ) . '"';
						}
					}
					if ( '' === $f['step'] ) {
						$extra .= ' step="any"';
					}
					$extra .= ' inputmode="decimal"';
				}
				$auto = array(
					'email' => 'email',
					'phone' => 'tel',
					'url'   => 'url',
				);
				if ( isset( $auto[ $type ] ) ) {
					$extra .= ' autocomplete="' . $auto[ $type ] . '"';
				}
				$inner = $label . '<input type="' . $html_type . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $ph . $extra . $aria . ( $req ? ' required' : '' ) . '>';
		}

		return '<div class="smokyforms-field smokyforms-type-' . esc_attr( $type ) . ' smokyforms-width-' . esc_attr( $f['width'] ) . '" data-field="' . esc_attr( $f['id'] ) . '">' . $inner . $help . $error . '</div>';
	}

	/**
	 * What the calculation script needs: fields used in formulas, formulas, tables and the number format.
	 *
	 * @param array $form Form.
	 * @return array
	 */
	private static function calc_config( $form ) {
		global $wp_locale;
		$fields = array();
		$calcs  = array();
		foreach ( $form['fields'] as $f ) {
			if ( 'calculation' === $f['type'] ) {
				$calcs[] = array(
					'id'       => $f['id'],
					'formula'  => $f['formula'],
					'decimals' => (int) $f['decimals'],
					'prefix'   => $f['prefix'],
					'suffix'   => $f['suffix'],
				);
			}
			if ( Fields::is_input( $f['type'] ) ) {
				$entry = array( 'type' => $f['type'] );
				if ( Fields::has_choices( $f['type'] ) ) {
					$entry['options'] = array();
					foreach ( $f['options'] as $o ) {
						$entry['options'][ $o['label'] ] = (float) $o['calc'];
					}
				}
				$fields[ $f['id'] ] = $entry;
			}
		}
		return array(
			'fields' => $fields,
			'calcs'  => $calcs,
			'tables' => $form['tables'],
			'number' => array(
				'decimal'   => isset( $wp_locale->number_format['decimal_point'] ) ? $wp_locale->number_format['decimal_point'] : '.',
				'thousands' => isset( $wp_locale->number_format['thousands_sep'] ) ? $wp_locale->number_format['thousands_sep'] : ',',
			),
		);
	}

	/**
	 * CAPTCHA widget of a form, and its script.
	 *
	 * @param array $form Form.
	 * @return string
	 */
	private static function captcha( $form ) {
		$service = Settings::get( 'captcha' );
		$key     = Settings::get( 'captcha_site_key' );
		if ( empty( $form['settings']['captcha'] ) || 'none' === $service || '' === $key ) {
			return '';
		}
		$scripts = array(
			'turnstile' => array( 'https://challenges.cloudflare.com/turnstile/v0/api.js', 'cf-turnstile' ),
			'recaptcha' => array( 'https://www.google.com/recaptcha/api.js', 'g-recaptcha' ),
			'hcaptcha'  => array( 'https://js.hcaptcha.com/1/api.js', 'h-captcha' ),
		);
		if ( ! isset( $scripts[ $service ] ) ) {
			return '';
		}
		// The provider's own script is required by its terms; it is loaded only on pages with a protected form.
		wp_enqueue_script( 'smokyforms-captcha-' . $service, $scripts[ $service ][0], array(), null, array( 'in_footer' => true, 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		return '<div class="smokyforms-captcha"><div class="' . esc_attr( $scripts[ $service ][1] ) . '" data-sitekey="' . esc_attr( $key ) . '"></div><p class="smokyforms-error" data-captcha-error hidden></p></div>';
	}

	/**
	 * Texts for form.js (validation messages in the site language).
	 */
	private static function localize() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		wp_localize_script(
			'smokyforms',
			'smokyformsText',
			array(
				'required' => __( 'This field is required.', 'smokyforms' ),
				'email'    => __( 'Please enter a valid email address.', 'smokyforms' ),
				'url'      => __( 'Please enter a valid website address.', 'smokyforms' ),
				'number'   => __( 'Please enter a number.', 'smokyforms' ),
				'sending'  => __( 'Sending…', 'smokyforms' ),
				'check'    => __( 'Please check the form and try again.', 'smokyforms' ),
				'failed'   => __( 'The form could not be sent. Please try again.', 'smokyforms' ),
			)
		);
	}
}
