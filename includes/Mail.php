<?php
/**
 * SMTP sending of SmokyForms.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms;

defined( 'ABSPATH' ) || exit;

/**
 * Sends e-mail through an SMTP account (option `smokyforms_mail`, kept by SmokyForms alone).
 * Scope: only the e-mails of SmokyForms (default), or every e-mail of the site. When another
 * SMTP plugin is active, SmokyForms does not send through its own SMTP account.
 */
final class Mail {

	const OPTION = 'smokyforms_mail';

	/**
	 * Depth of wrap() calls (plugin-scoped sending).
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Last error of a send.
	 *
	 * @var string
	 */
	private static $error = '';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_smokyforms_mail_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_smokyforms_mail_test', array( __CLASS__, 'test' ) );
		if ( self::active() ) {
			add_action( 'phpmailer_init', array( __CLASS__, 'configure' ), PHP_INT_MAX );
			add_filter( 'wp_mail_from', array( __CLASS__, 'from' ), PHP_INT_MAX );
			add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ), PHP_INT_MAX );
		}
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'   => false,
			'scope'     => 'plugins',
			'host'      => '',
			'port'      => 587,
			'secure'    => 'tls',
			'user'      => '',
			'pass'      => '',
			'from'      => '',
			'from_name' => '',
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $s ) ? $s : array() );
	}

	/**
	 * Another SMTP plugin that is active, if any.
	 *
	 * @return string Its name, or ''.
	 */
	public static function conflict() {
		$known = array(
			'WP Mail SMTP'      => class_exists( '\WPMailSMTP\Core' ),
			'FluentSMTP'        => defined( 'FLUENTMAIL' ),
			'Post SMTP'         => class_exists( 'PostmanOptions' ) || defined( 'POST_SMTP_VER' ),
			'Easy WP SMTP'      => class_exists( 'EasyWPSMTP' ) || function_exists( 'swpsmtp_init_smtp' ),
			'SMTP Mailer'       => function_exists( 'smtp_mailer_init' ) || class_exists( 'SMTP_MAILER' ),
			'Gmail SMTP'        => class_exists( 'GMAIL_SMTP' ),
			'Solid Mail'        => defined( 'SOLID_MAIL_VERSION' ),
			'SureMail'          => defined( 'SUREMAILS_VERSION' ),
		);
		foreach ( $known as $name => $found ) {
			if ( $found ) {
				return $name;
			}
		}
		return (string) apply_filters( 'smokyforms_mail_conflict', '' );
	}

	/**
	 * Whether the module sends mail (switched on, a server set and no other SMTP plugin).
	 *
	 * @return bool
	 */
	public static function active() {
		$s = self::settings();
		return ! empty( $s['enabled'] ) && '' !== trim( (string) $s['host'] ) && '' === self::conflict();
	}

	/**
	 * Runs a send of SmokyForms; with the "plugins only" scope the SMTP account
	 * is used only inside this call.
	 *
	 * @param callable $send Function that calls wp_mail() and returns its result.
	 * @return mixed What $send returned.
	 */
	public static function wrap( $send ) {
		self::$depth++;
		try {
			return call_user_func( $send );
		} finally {
			self::$depth--;
		}
	}

	/**
	 * Whether the current e-mail goes through the SMTP account.
	 *
	 * @return bool
	 */
	private static function applies() {
		if ( ! self::active() ) {
			return false;
		}
		$s = self::settings();
		return 'all' === $s['scope'] || self::$depth > 0;
	}

	/**
	 * Sets up PHPMailer.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Mailer.
	 */
	public static function configure( $mailer ) {
		if ( ! self::applies() ) {
			return;
		}
		$s = self::settings();
		$mailer->isSMTP();
		$mailer->Host        = trim( (string) $s['host'] );
		$mailer->Port        = max( 1, (int) $s['port'] );
		$mailer->SMTPSecure  = in_array( $s['secure'], array( 'ssl', 'tls' ), true ) ? $s['secure'] : '';
		$mailer->SMTPAutoTLS = '' !== $mailer->SMTPSecure; // No encryption chosen: do not switch to it on its own.
		$mailer->SMTPAuth    = '' !== (string) $s['user'];
		if ( $mailer->SMTPAuth ) {
			$mailer->Username = (string) $s['user'];
			$mailer->Password = self::password();
		}
		$mailer->Timeout = 20;
	}

	/**
	 * From address.
	 *
	 * @param string $email Address.
	 * @return string
	 */
	public static function from( $email ) {
		if ( ! self::applies() ) {
			return $email;
		}
		$from = (string) self::settings()['from'];
		return is_email( $from ) ? $from : $email;
	}

	/**
	 * From name.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function from_name( $name ) {
		if ( ! self::applies() ) {
			return $name;
		}
		$from = trim( (string) self::settings()['from_name'] );
		return '' !== $from ? $from : $name;
	}

	/**
	 * The password: a wp-config constant wins, otherwise the stored, encrypted value.
	 *
	 * @return string
	 */
	public static function password() {
		if ( self::password_from_constant() ) {
			return (string) constant( 'SMOKYFORMS_SMTP_PASSWORD' );
		}
		return self::decrypt( (string) self::settings()['pass'], 'smokyforms-smtp' );
	}

	/**
	 * Whether the password comes from wp-config.
	 *
	 * @return bool
	 */
	public static function password_from_constant() {
		return defined( 'SMOKYFORMS_SMTP_PASSWORD' ) && '' !== (string) constant( 'SMOKYFORMS_SMTP_PASSWORD' );
	}

	/**
	 * Encryption key.
	 *
	 * @param string $purpose Key purpose.
	 * @return string
	 */
	private static function key( $purpose ) {
		return hash( 'sha256', wp_salt( 'auth' ) . $purpose, true );
	}

	/**
	 * Encrypts a password for storage.
	 *
	 * @param string $plain Password.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		if ( '' === $plain ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'sf1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key( 'smokyforms-smtp' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypts a stored password.
	 *
	 * @param string $stored  Stored value.
	 * @param string $purpose Key purpose.
	 * @return string
	 */
	private static function decrypt( $stored, $purpose ) {
		$prefix = substr( $stored, 0, 4 );
		if ( ! in_array( $prefix, array( 'sf1:', 'sp1:' ), true ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key( $purpose ) );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Capability for the settings.
	 *
	 * @return string
	 */
	private static function cap() {
		return (string) apply_filters( 'smokyforms_mail_capability', 'manage_options' );
	}

	/**
	 * Saves the settings form.
	 */
	public static function save() {
		check_admin_referer( 'smokyforms_mail_save' );
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'smokyforms' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$old  = self::settings();
		$post = wp_unslash( $_POST );
		$new  = array(
			'enabled'   => ! empty( $post['enabled'] ),
			'scope'     => isset( $post['scope'] ) && 'all' === $post['scope'] ? 'all' : 'plugins',
			'host'      => isset( $post['host'] ) ? sanitize_text_field( $post['host'] ) : '',
			'port'      => isset( $post['port'] ) ? max( 1, min( 65535, absint( $post['port'] ) ) ) : 587,
			'secure'    => isset( $post['secure'] ) && in_array( $post['secure'], array( 'ssl', 'tls', '' ), true ) ? $post['secure'] : 'tls',
			'user'      => isset( $post['user'] ) ? sanitize_text_field( $post['user'] ) : '',
			'pass'      => $old['pass'],
			'from'      => isset( $post['from'] ) ? sanitize_email( $post['from'] ) : '',
			'from_name' => isset( $post['from_name'] ) ? sanitize_text_field( $post['from_name'] ) : '',
		);
		if ( ! empty( $post['pass_clear'] ) ) {
			$new['pass'] = '';
		} elseif ( isset( $post['pass'] ) && '' !== (string) $post['pass'] ) {
			$new['pass'] = self::encrypt( (string) $post['pass'] );
		}
		// phpcs:enable
		update_option( self::OPTION, $new, false );
		wp_safe_redirect( add_query_arg( 'smokyforms_mail', 'saved', wp_get_referer() ? wp_get_referer() : admin_url() ) );
		exit;
	}

	/**
	 * Sends a test e-mail.
	 */
	public static function test() {
		check_admin_referer( 'smokyforms_mail_test' );
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'smokyforms' ) );
		}
		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}
		self::$error = '';
		$catch       = static function ( $error ) {
			self::$error = is_wp_error( $error ) ? $error->get_error_message() : '';
		};
		add_action( 'wp_mail_failed', $catch );
		$ok = self::wrap(
			static function () use ( $to ) {
				return wp_mail(
					$to,
					__( 'Test email', 'smokyforms' ),
					__( 'This is a test email. If you can read it, email sending works.', 'smokyforms' )
				);
			}
		);
		remove_action( 'wp_mail_failed', $catch );
		set_transient( 'smokyforms_mail_test_' . get_current_user_id(), array( $ok, self::$error, $to ), 120 );
		wp_safe_redirect( add_query_arg( 'smokyforms_mail', 'tested', wp_get_referer() ? wp_get_referer() : admin_url() ) );
		exit;
	}

	/**
	 * Settings form. Plugins call this from their own settings screen.
	 *
	 * @param string $back URL of the screen (the forms return there).
	 */
	public static function settings_page( $back ) {
		$s        = self::settings();
		$conflict = self::conflict();
		$state    = isset( $_GET['smokyforms_mail'] ) ? sanitize_key( $_GET['smokyforms_mail'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'saved' === $state ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'smokyforms' ) . '</p></div>';
		} elseif ( 'tested' === $state ) {
			$result = get_transient( 'smokyforms_mail_test_' . get_current_user_id() );
			if ( is_array( $result ) ) {
				if ( $result[0] ) {
					/* translators: %s: email address */
					echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'The test email was sent to %s. Check the inbox and the spam folder.', 'smokyforms' ), $result[2] ) ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'The test email could not be sent.', 'smokyforms' ) . ( $result[1] ? ' ' . esc_html( $result[1] ) : '' ) . '</p></div>';
				}
			}
		}
		?>
		<p><?php esc_html_e( 'Many hosts deliver the emails of WordPress late, or into the spam folder. Sending them through the SMTP account of your email provider fixes that, without a separate SMTP plugin.', 'smokyforms' ); ?></p>
		<?php if ( $conflict ) : ?>
			<?php /* translators: %s: name of another SMTP plugin */ ?>
			<div class="notice notice-info inline"><p><?php echo esc_html( sprintf( __( '%s is active and handles email sending, so these settings are not used.', 'smokyforms' ), $conflict ) ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
			<?php wp_nonce_field( 'smokyforms_mail_save' ); ?>
			<input type="hidden" name="action" value="smokyforms_mail_save">
			<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( remove_query_arg( 'smokyforms_mail', $back ) ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'SMTP', 'smokyforms' ); ?></th>
					<td>
						<label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Send emails through an SMTP account', 'smokyforms' ); ?></label>
						<fieldset style="margin-top:8px;">
							<label><input type="radio" name="scope" value="plugins" <?php checked( 'all' !== $s['scope'] ); ?>> <?php esc_html_e( 'Only the emails of SmokySystems plugins', 'smokyforms' ); ?></label><br>
							<label><input type="radio" name="scope" value="all" <?php checked( 'all' === $s['scope'] ); ?>> <?php esc_html_e( 'All emails of this site', 'smokyforms' ); ?></label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="smokyforms-mail-host"><?php esc_html_e( 'SMTP server', 'smokyforms' ); ?></label></th>
					<td><input type="text" id="smokyforms-mail-host" name="host" class="regular-text" autocomplete="off" value="<?php echo esc_attr( (string) $s['host'] ); ?>" placeholder="smtp.example.com"></td>
				</tr>
				<tr>
					<th scope="row"><label for="smokyforms-mail-port"><?php esc_html_e( 'Port', 'smokyforms' ); ?></label></th>
					<td>
						<input type="number" id="smokyforms-mail-port" name="port" class="small-text" min="1" max="65535" value="<?php echo esc_attr( (string) $s['port'] ); ?>">
						<select name="secure" aria-label="<?php esc_attr_e( 'Encryption', 'smokyforms' ); ?>">
							<option value="tls" <?php selected( $s['secure'], 'tls' ); ?>>TLS (587)</option>
							<option value="ssl" <?php selected( $s['secure'], 'ssl' ); ?>>SSL (465)</option>
							<option value="" <?php selected( $s['secure'], '' ); ?>><?php esc_html_e( 'No encryption', 'smokyforms' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="smokyforms-mail-user"><?php esc_html_e( 'Username', 'smokyforms' ); ?></label></th>
					<td><input type="text" id="smokyforms-mail-user" name="user" class="regular-text" autocomplete="off" value="<?php echo esc_attr( (string) $s['user'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="smokyforms-mail-pass"><?php esc_html_e( 'Password', 'smokyforms' ); ?></label></th>
					<td>
						<?php if ( self::password_from_constant() ) : ?>
							<p><?php esc_html_e( 'The password is set in wp-config.php.', 'smokyforms' ); ?></p>
						<?php else : ?>
							<input type="password" id="smokyforms-mail-pass" name="pass" class="regular-text" autocomplete="new-password" value="">
							<?php if ( '' !== (string) $s['pass'] ) : ?>
								<p class="description"><?php esc_html_e( 'A password is saved. Leave this empty to keep it.', 'smokyforms' ); ?>
									<label><input type="checkbox" name="pass_clear" value="1"> <?php esc_html_e( 'Remove the saved password', 'smokyforms' ); ?></label></p>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="smokyforms-mail-from"><?php esc_html_e( 'From email', 'smokyforms' ); ?></label></th>
					<td>
						<input type="email" id="smokyforms-mail-from" name="from" class="regular-text" value="<?php echo esc_attr( (string) $s['from'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Use an address of the SMTP account, otherwise many providers refuse the email.', 'smokyforms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="smokyforms-mail-from-name"><?php esc_html_e( 'From name', 'smokyforms' ); ?></label></th>
					<td><input type="text" id="smokyforms-mail-from-name" name="from_name" class="regular-text" value="<?php echo esc_attr( (string) $s['from_name'] ); ?>" placeholder="<?php echo esc_attr( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>"></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<h2><?php esc_html_e( 'Test email', 'smokyforms' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
			<?php wp_nonce_field( 'smokyforms_mail_test' ); ?>
			<input type="hidden" name="action" value="smokyforms_mail_test">
			<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( remove_query_arg( 'smokyforms_mail', $back ) ); ?>">
			<label for="smokyforms-mail-to" class="screen-reader-text"><?php esc_html_e( 'Send to', 'smokyforms' ); ?></label>
			<input type="email" id="smokyforms-mail-to" name="to" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">
			<?php submit_button( __( 'Send test email', 'smokyforms' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}
}
