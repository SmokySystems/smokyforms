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
		wp_register_script( 'smokyforms-signature', SMOKYFORMS_URL . 'assets/js/signature.js', array(), SMOKYFORMS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );

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
				// The form's own CSS in the editor too, so the preview looks like the page.
				'editor_style'    => 'smokyforms',
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
		$atts = shortcode_atts(
			array(
				'id'          => 0,
				'title'       => '',
				'description' => '',
			),
			$atts,
			'smokyforms'
		);
		return self::render(
			(int) $atts['id'],
			array(
				'title'       => self::yes( $atts['title'] ),
				'description' => self::yes( $atts['description'] ),
			)
		);
	}

	/**
	 * Whether a shortcode option is switched on (yes, true, 1, on).
	 *
	 * @param string $v Value.
	 * @return bool
	 */
	private static function yes( $v ) {
		return in_array( strtolower( trim( (string) $v ) ), array( 'yes', 'true', '1', 'on' ), true );
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function block( $attributes ) {
		return self::render(
			isset( $attributes['formId'] ) ? (int) $attributes['formId'] : 0,
			array(
				'title'       => ! empty( $attributes['showTitle'] ),
				'description' => ! empty( $attributes['showDescription'] ),
			)
		);
	}

	/**
	 * HTML of a form.
	 *
	 * @param int   $id   Form id.
	 * @param array $show Show the form's title and description above it (title, description).
	 * @return string
	 */
	public static function render( $id, $show = array() ) {
		$form = Forms::get( $id );
		if ( ! $form || 'publish' !== $form['status'] ) {
			return current_user_can( 'edit_posts' ) ? '<p>' . esc_html__( 'SmokyForms: this form does not exist.', 'smokyforms' ) . '</p>' : '';
		}
		self::$count++;
		$uid   = 'smokyforms-' . $form['id'] . '-' . self::$count;
		$types = wp_list_pluck( $form['fields'], 'type' );
		$logic = false;
		foreach ( $form['fields'] as $f ) {
			if ( ! empty( $f['logic'] ) ) {
				$logic = true;
			}
		}
		$has_calc = in_array( 'calculation', $types, true );

		wp_enqueue_style( 'smokyforms' );
		if ( $has_calc ) {
			wp_enqueue_script( 'smokyforms-calc' );
		}
		if ( in_array( 'signature', $types, true ) ) {
			wp_enqueue_script( 'smokyforms-signature' );
		}
		wp_enqueue_script( 'smokyforms' );
		self::localize();

		// Fields hidden by their logic with the default answers start hidden (shown again when JavaScript is off).
		$hidden = $logic ? Logic::hidden( $form, Forms::calculate( $form, self::default_values( $form ) ) ) : array();

		// Page breaks split the form into steps.
		$steps = array(
			array(
				'break'  => null,
				'fields' => array(),
			),
		);
		foreach ( $form['fields'] as $f ) {
			if ( 'page' === $f['type'] ) {
				$steps[] = array(
					'break'  => $f,
					'fields' => array(),
				);
			} else {
				$steps[ count( $steps ) - 1 ]['fields'][] = $f;
			}
		}
		$steps = array_values(
			array_filter(
				$steps,
				static function ( $st ) {
					return (bool) $st['fields'];
				}
			)
		);
		$multi = count( $steps ) > 1;

		$captcha = self::captcha( $form );

		ob_start();
		// Message after a submission without JavaScript (see Submission::fallback()).
		$sent = isset( $_GET['smokyforms_sent'] ) ? absint( $_GET['smokyforms_sent'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$fail = isset( $_GET['smokyforms_error'] ) ? absint( $_GET['smokyforms_error'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo self::button_style( $form, $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- colours are sanitize_hex_color() values.
		if ( $hidden && 1 === self::$count ) {
			echo '<noscript><style>.smokyforms .smokyforms-field[data-logic][hidden]{display:block!important}</style></noscript>';
		}
		?>
		<form id="<?php echo esc_attr( $uid ); ?>" class="smokyforms" method="post" novalidate
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			data-endpoint="<?php echo esc_url( rest_url( 'smokyforms/v1/submit/' . $form['id'] ) ); ?>"
			<?php if ( in_array( 'file', $types, true ) ) : ?>
			enctype="multipart/form-data"
			<?php endif; ?>
			<?php if ( $has_calc ) : ?>
			data-calc="<?php echo esc_attr( wp_json_encode( self::calc_config( $form ) ) ); ?>"
			<?php endif; ?>
			<?php if ( $form['settings']['redirect'] ) : ?>
			data-redirect="<?php echo esc_url( $form['settings']['redirect'] ); ?>"
			<?php endif; ?>
			<?php if ( $multi ) : ?>
			data-steps="<?php echo esc_attr( (string) count( $steps ) ); ?>"
			<?php endif; ?>
		>
			<input type="hidden" name="action" value="smokyforms_submit">
			<input type="hidden" name="smokyforms_id" value="<?php echo esc_attr( (string) $form['id'] ); ?>">
			<input type="hidden" name="smokyforms_t" value="<?php echo esc_attr( Submission::time_token() ); ?>">
			<?php if ( ! empty( $show['title'] ) || ( ! empty( $show['description'] ) && '' !== trim( $form['settings']['description'] ) ) ) : ?>
			<div class="smokyforms-header">
				<?php if ( ! empty( $show['title'] ) ) : ?>
				<h2 class="smokyforms-title"><?php echo esc_html( $form['title'] ); ?></h2>
				<?php endif; ?>
				<?php if ( ! empty( $show['description'] ) && '' !== trim( $form['settings']['description'] ) ) : ?>
				<div class="smokyforms-description"><?php echo wp_kses_post( wpautop( $form['settings']['description'] ) ); ?></div>
				<?php endif; ?>
			</div>
			<?php endif; ?>
			<?php if ( $multi && ! empty( $form['settings']['progress'] ) ) : ?>
			<div class="smokyforms-progress" hidden>
				<p class="smokyforms-progress-text"></p>
				<div class="smokyforms-progress-bar" aria-hidden="true"><span></span></div>
			</div>
			<?php endif; ?>
			<?php
			foreach ( $steps as $i => $step ) {
				if ( $multi ) {
					$next = isset( $steps[ $i + 1 ] ) ? $steps[ $i + 1 ]['break'] : null;
					$prev = $step['break'];
					echo '<div class="smokyforms-step" data-step="' . esc_attr( (string) $i ) . '"'
						. ( $next ? ' data-next="' . esc_attr( '' !== $next['next_label'] ? $next['next_label'] : __( 'Next', 'smokyforms' ) ) . '"' : '' )
						. ( $prev ? ' data-prev="' . esc_attr( '' !== $prev['prev_label'] ? $prev['prev_label'] : __( 'Previous', 'smokyforms' ) ) . '"' : '' )
						. '>';
					if ( $prev && '' !== $prev['label'] ) {
						echo '<h3 class="smokyforms-step-title">' . esc_html( $prev['label'] ) . '</h3>';
					}
				}
				echo '<div class="smokyforms-fields">';
				foreach ( $step['fields'] as $f ) {
					echo self::with_logic( $f, self::field( $f, $uid ), isset( $hidden[ $f['id'] ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in field().
				}
				echo '</div>';
				if ( $multi ) {
					echo '</div>';
				}
			}
			?>
			<div class="smokyforms-hp" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">
				<label><?php esc_html_e( 'Leave this field empty', 'smokyforms' ); ?>
					<input type="text" name="smokyforms_website" value="" tabindex="-1" autocomplete="off">
				</label>
			</div>
			<?php echo $captcha; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
			<div class="smokyforms-submit">
				<?php if ( $multi ) : ?>
				<button type="button" class="smokyforms-button smokyforms-prev wp-element-button" hidden></button>
				<button type="button" class="smokyforms-button smokyforms-next wp-element-button" hidden></button>
				<?php endif; ?>
				<button type="submit" class="smokyforms-button smokyforms-send wp-element-button"><?php echo esc_html( $form['settings']['submit_label'] ); ?></button>
			</div>
			<div class="smokyforms-message" role="status" aria-live="polite"
				<?php echo ( $sent === $form['id'] || $fail === $form['id'] ) ? '' : 'hidden'; ?>
				<?php echo $fail === $form['id'] ? 'data-state="error"' : ( $sent === $form['id'] ? 'data-state="success"' : '' ); ?>>
				<?php
				if ( $sent === $form['id'] ) {
					echo wp_kses_post( wpautop( Notifications::replace( $form['settings']['success_message'], $form, array(), true ) ) );
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
	 * Colours chosen for the buttons of a form. Without them the buttons look like the theme's buttons.
	 *
	 * @param array  $form Form.
	 * @param string $uid  Form element id.
	 * @return string
	 */
	private static function button_style( $form, $uid ) {
		$b    = $form['settings']['button'] ?? array();
		$sel  = '#' . $uid . ' .smokyforms-button';
		$css  = '';
		$base = ( ! empty( $b['bg'] ) ? 'background:' . $b['bg'] . ';border-color:' . $b['bg'] . ';' : '' ) . ( ! empty( $b['text'] ) ? 'color:' . $b['text'] . ';' : '' );
		$over = ( ! empty( $b['bg_hover'] ) ? 'background:' . $b['bg_hover'] . ';border-color:' . $b['bg_hover'] . ';' : '' ) . ( ! empty( $b['text_hover'] ) ? 'color:' . $b['text_hover'] . ';' : '' );
		if ( $base ) {
			$css .= $sel . '{' . $base . '}';
		}
		if ( $over ) {
			$css .= $sel . ':hover,' . $sel . ':focus-visible{' . $over . '}';
		}
		return $css ? '<style>' . $css . '</style>' : '';
	}

	/**
	 * Adds the logic rules (and the starting state) to a field's wrapper.
	 *
	 * @param array  $f      Field.
	 * @param string $html   Field HTML.
	 * @param bool   $hidden Whether it starts hidden.
	 * @return string
	 */
	private static function with_logic( $f, $html, $hidden ) {
		if ( empty( $f['logic'] ) || ( 'calculation' === $f['type'] && empty( $f['visible'] ) ) || 0 !== strpos( $html, '<div ' ) ) {
			return $html;
		}
		return '<div data-logic="' . esc_attr( wp_json_encode( $f['logic'] ) ) . '"' . ( $hidden ? ' hidden' : '' ) . ' ' . substr( $html, 5 );
	}

	/**
	 * Answers of an untouched form (default values and preselected choices).
	 *
	 * @param array $form Form.
	 * @return array
	 */
	private static function default_values( $form ) {
		$values = array();
		foreach ( $form['fields'] as $f ) {
			if ( ! Fields::is_input( $f['type'] ) || 'calculation' === $f['type'] ) {
				continue;
			}
			if ( Fields::has_choices( $f['type'] ) ) {
				$chosen = array();
				foreach ( $f['options'] as $o ) {
					if ( ! empty( $o['selected'] ) ) {
						$chosen[] = $o['label'];
					}
				}
				$values[ $f['id'] ] = 'checkbox' === $f['type'] ? $chosen : (string) reset( $chosen );
			} elseif ( in_array( $f['type'], array( 'file', 'repeater' ), true ) ) {
				$values[ $f['id'] ] = array();
			} else {
				$values[ $f['id'] ] = (string) $f['default'];
			}
		}
		return $values;
	}

	/**
	 * HTML of one field.
	 *
	 * @param array  $f   Field.
	 * @param string $uid Form element id.
	 * @param array  $ctx For a field inside a repeating group: id, name and key of this copy.
	 * @return string
	 */
	private static function field( $f, $uid, $ctx = array() ) {
		$type  = $f['type'];
		$id    = $ctx['id'] ?? $uid . '-' . $f['id'];
		$name  = $ctx['name'] ?? 'smokyforms[' . $f['id'] . ']';
		$key   = $ctx['key'] ?? $f['id'];
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
				return '<div class="smokyforms-field smokyforms-type-heading" data-field="' . esc_attr( $key ) . '"><h3 class="smokyforms-heading">' . esc_html( $f['label'] ) . '</h3>' . $help . '</div>';

			case 'html':
				return '<div class="smokyforms-field smokyforms-type-html" data-field="' . esc_attr( $key ) . '">' . wp_kses_post( wpautop( $f['content'] ) ) . '</div>';

			case 'hidden':
				return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" data-field="' . esc_attr( $f['id'] ) . '">';

			case 'page':
				return '';

			case 'rating':
				$items = '';
				for ( $i = 1; $i <= $f['max']; $i++ ) {
					/* translators: %s: number of stars */
					$stars  = sprintf( _n( '%s star', '%s stars', $i, 'smokyforms' ), number_format_i18n( $i ) );
					$oid    = $id . '-' . $i;
					$items .= '<input type="radio" class="smokyforms-star-input" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $name ) . '" value="' . $i . '"' . checked( (string) $i, $value, false ) . '>'
						. '<label class="smokyforms-star" for="' . esc_attr( $oid ) . '" title="' . esc_attr( $stars ) . '"><span class="smokyforms-star-icon" aria-hidden="true">&#9733;</span><span class="smokyforms-sr">' . esc_html( $stars ) . '</span></label>';
				}
				$inner = '<fieldset class="smokyforms-choices smokyforms-rating" id="' . esc_attr( $id ) . '"' . $aria . '><legend class="smokyforms-label">' . esc_html( $f['label'] ) . $star . '</legend><span class="smokyforms-stars">' . $items . '</span></fieldset>';
				break;

			case 'file':
				$exts   = array_filter( array_map( 'trim', explode( ',', $f['extensions'] ) ) );
				$mb     = number_format_i18n( $f['max_size'], floor( $f['max_size'] ) == $f['max_size'] ? 0 : 1 ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
				/* translators: 1: file types, for example "jpg, png, pdf", 2: size in MB */
				$note   = sprintf( __( 'Allowed file types: %1$s. Up to %2$s MB per file.', 'smokyforms' ), implode( ', ', $exts ), $mb );
				/* translators: %s: number of files */
				$many   = sprintf( _n( 'You can upload at most %s file.', 'You can upload at most %s files.', $f['max_files'], 'smokyforms' ), number_format_i18n( $f['max_files'] ) );
				$accept = implode( ',', array_map( static function ( $e ) {
					return '.' . $e;
				}, $exts ) );
				$inner  = $label . '<input type="file" id="' . esc_attr( $id ) . '" name="' . esc_attr( 'smokyforms_files[' . $f['id'] . '][]' ) . '" accept="' . esc_attr( $accept ) . '"'
					. ( $f['max_files'] > 1 ? ' multiple' : '' )
					. ' data-extensions="' . esc_attr( implode( ',', $exts ) ) . '" data-max-size="' . esc_attr( (string) (int) ( $f['max_size'] * MB_IN_BYTES ) ) . '" data-max-mb="' . esc_attr( $mb ) . '"'
					. ' data-max-files="' . esc_attr( (string) $f['max_files'] ) . '" data-too-many="' . esc_attr( $many ) . '"' . $aria . ( $req ? ' required' : '' ) . '>'
					. '<p class="smokyforms-help">' . esc_html( $note . ( $f['max_files'] > 1 ? ' ' . $many : '' ) ) . '</p>';
				break;

			case 'signature':
				$inner = '<span class="smokyforms-label" id="' . esc_attr( $id ) . '-label">' . esc_html( $f['label'] ) . $star . '</span>'
					. '<div class="smokyforms-signature"><canvas class="smokyforms-signature-pad" id="' . esc_attr( $id ) . '" role="img" aria-labelledby="' . esc_attr( $id ) . '-label" aria-describedby="' . esc_attr( $descr ) . '"></canvas>'
					. '<button type="button" class="smokyforms-signature-clear">' . esc_html__( 'Clear', 'smokyforms' ) . '</button></div>'
					. '<input type="hidden" name="' . esc_attr( $name ) . '" value="" data-signature>'
					. '<noscript><p class="smokyforms-help">' . esc_html__( 'Signing needs JavaScript. Please switch it on in your browser.', 'smokyforms' ) . '</p></noscript>';
				return '<div class="smokyforms-field smokyforms-type-signature smokyforms-width-' . esc_attr( $f['width'] ) . '" data-field="' . esc_attr( $key ) . '"' . ( $req ? ' data-required="1"' : '' ) . '>' . $inner . $help . $error . '</div>';

			case 'repeater':
				$rows = max( 1, $f['min_rows'] );
				$body = '';
				for ( $i = 0; $i < $rows; $i++ ) {
					$body .= self::row( $f, $uid, (string) $i );
				}
				$add   = '' !== $f['add_label'] ? $f['add_label'] : __( 'Add another', 'smokyforms' );
				$inner = '<fieldset class="smokyforms-repeater" id="' . esc_attr( $id ) . '"' . $aria . ' data-min="' . esc_attr( (string) $f['min_rows'] ) . '" data-max="' . esc_attr( (string) $f['max_rows'] ) . '" data-next="' . esc_attr( (string) $rows ) . '">'
					. '<legend class="smokyforms-label">' . esc_html( $f['label'] ) . $star . '</legend>'
					. '<div class="smokyforms-rows">' . $body . '</div>'
					. '<template class="smokyforms-row-template">' . self::row( $f, $uid, '__i__' ) . '</template>'
					. '<button type="button" class="smokyforms-add-row" hidden>' . esc_html( $add ) . '</button></fieldset>';
				break;

			case 'textarea':
				$inner = $label . '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="5"' . $ph . $aria . ( $req ? ' required' : '' ) . '>' . esc_textarea( $value ) . '</textarea>';
				break;

			case 'select':
				$opts = '<option value="">' . esc_html( '' !== $f['placeholder'] ? $f['placeholder'] : __( 'Choose…', 'smokyforms' ) ) . '</option>';
				foreach ( $f['options'] as $o ) {
					$opts .= '<option value="' . esc_attr( $o['label'] ) . '"' . self::calc_attr( $o ) . selected( ! empty( $o['selected'] ), true, false ) . '>' . esc_html( $o['label'] ) . '</option>';
				}
				$inner = $label . '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $aria . ( $req ? ' required' : '' ) . '>' . $opts . '</select>';
				break;

			case 'radio':
			case 'checkbox':
				$items = '';
				foreach ( $f['options'] as $i => $o ) {
					$oid    = $id . '-' . $i;
					$items .= '<label class="smokyforms-choice" for="' . esc_attr( $oid ) . '"><input type="' . $type . '" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $name . ( 'checkbox' === $type ? '[]' : '' ) ) . '" value="' . esc_attr( $o['label'] ) . '"' . self::calc_attr( $o ) . checked( ! empty( $o['selected'] ), true, false ) . '> <span>' . esc_html( $o['label'] ) . '</span></label>';
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
				return '<div class="smokyforms-field smokyforms-type-calculation smokyforms-width-' . esc_attr( $f['width'] ) . '" data-field="' . esc_attr( $key ) . '"' . $hidden . '>' . $inner . $help . '</div>';

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

		return '<div class="smokyforms-field smokyforms-type-' . esc_attr( $type ) . ' smokyforms-width-' . esc_attr( $f['width'] ) . '" data-field="' . esc_attr( $key ) . '"' . ( $req && 'repeater' === $type ? ' data-required="1"' : '' ) . '>' . $inner . $help . $error . '</div>';
	}

	/**
	 * One row of a repeating group.
	 *
	 * @param array  $f   Repeating group.
	 * @param string $uid Form element id.
	 * @param string $i   Row number ("__i__" in the template for new rows).
	 * @return string
	 */
	private static function row( $f, $uid, $i ) {
		$html = '<div class="smokyforms-row" data-row="' . esc_attr( $i ) . '"><div class="smokyforms-fields">';
		foreach ( $f['subfields'] as $s ) {
			$html .= self::field(
				$s,
				$uid,
				array(
					'id'   => $uid . '-' . $f['id'] . '-' . $i . '-' . $s['id'],
					'name' => 'smokyforms[' . $f['id'] . '][' . $i . '][' . $s['id'] . ']',
					'key'  => $f['id'] . '.' . $i . '.' . $s['id'],
				)
			);
		}
		return $html . '</div><button type="button" class="smokyforms-remove-row" hidden aria-label="' . esc_attr__( 'Remove this row', 'smokyforms' ) . '" title="' . esc_attr__( 'Remove this row', 'smokyforms' ) . '">&times;</button></div>';
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
					$entry['options'] = self::calc_options( $f );
				}
				if ( 'repeater' === $f['type'] ) {
					$entry['subs'] = array();
					foreach ( $f['subfields'] as $sub ) {
						$entry['subs'][ $sub['id'] ] = Fields::has_choices( $sub['type'] ) ? array( 'type' => $sub['type'], 'options' => self::calc_options( $sub ) ) : array( 'type' => $sub['type'] );
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
	 * Calculation value of a choice as an attribute (used by conditional logic in the browser).
	 *
	 * @param array $o Choice.
	 * @return string
	 */
	private static function calc_attr( $o ) {
		return ! empty( $o['calc'] ) ? ' data-calc="' . esc_attr( Fields::number_text( $o['calc'] ) ) . '"' : '';
	}

	/**
	 * Calculation values of a choice field by label.
	 *
	 * @param array $f Field with options.
	 * @return array<string,float>
	 */
	private static function calc_options( $f ) {
		$out = array();
		foreach ( $f['options'] as $o ) {
			$out[ $o['label'] ] = (float) $o['calc'];
		}
		return $out;
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
				/* translators: 1: number of the current step, 2: number of steps */
				'step'     => __( 'Step %1$s of %2$s', 'smokyforms' ),
				/* translators: 1: file name, 2: size in MB */
				'tooBig'   => __( '%1$s is larger than %2$s MB.', 'smokyforms' ),
				/* translators: %s: file name */
				'badType'  => __( '%s: this file type is not allowed.', 'smokyforms' ),
				'rating'   => __( 'Please choose one of the options.', 'smokyforms' ),
			)
		);
	}
}
