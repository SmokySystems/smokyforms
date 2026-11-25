<?php
/**
 * Settings screen.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms\Admin;

use SmokySystems\SmokyForms\Settings as Options;

defined( 'ABSPATH' ) || exit;

/**
 * Spam protection, the undelivered safety net and e-mail sending (SMTP).
 */
final class Settings {

	const SLUG = 'smokyforms-settings';

	/**
	 * Registers hooks.
	 */
	public static function hooks() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Registers the option.
	 */
	public static function register() {
		register_setting(
			'smokyforms_settings',
			Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Options::class, 'sanitize' ),
				'default'           => Options::defaults(),
			)
		);
	}

	/**
	 * Settings screen.
	 */
	public static function page() {
		if ( ! current_user_can( Menu::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'smokyforms' ) );
		}
		$s    = Options::all();
		$name = Options::OPTION;
		$tab  = isset( $_GET['tab'] ) && 'email' === $_GET['tab'] ? 'email' : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base = admin_url( Menu::PARENT . '&page=' . self::SLUG );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SmokyForms Settings', 'smokyforms' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $base ); ?>" class="nav-tab <?php echo 'general' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'General', 'smokyforms' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'email', $base ) ); ?>" class="nav-tab <?php echo 'email' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Email sending', 'smokyforms' ); ?></a>
			</nav>
			<?php if ( 'email' === $tab ) : ?>
				<?php \SmokySystems\SmokyForms\Mail::settings_page( add_query_arg( 'tab', 'email', $base ) ); ?>
			<?php else : ?>
			<form method="post" action="options.php" novalidate>
				<?php settings_fields( 'smokyforms_settings' ); ?>
				<h2><?php esc_html_e( 'Spam protection', 'smokyforms' ); ?></h2>
				<p><?php esc_html_e( 'Every form has a hidden trap field and a time check. You can add a CAPTCHA on top; it is used on every form where it is switched on in the form settings.', 'smokyforms' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="smokyforms-captcha"><?php esc_html_e( 'CAPTCHA', 'smokyforms' ); ?></label></th>
						<td>
							<select id="smokyforms-captcha" name="<?php echo esc_attr( $name ); ?>[captcha]">
								<?php foreach ( Options::captcha_services() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['captcha'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Cloudflare Turnstile is free and usually needs no puzzle from your visitors.', 'smokyforms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="smokyforms-math"><?php esc_html_e( 'Math question', 'smokyforms' ); ?></label></th>
						<td>
							<label><input type="checkbox" id="smokyforms-math" name="<?php echo esc_attr( $name ); ?>[math]" value="1" <?php checked( ! empty( $s['math'] ) ); ?>> <?php esc_html_e( 'Ask a simple math question', 'smokyforms' ); ?></label>
							<p class="description"><?php esc_html_e( 'Visitors solve a small sum such as 7 + 4. It needs no account or key, and works on its own or together with a CAPTCHA.', 'smokyforms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="smokyforms-site-key"><?php esc_html_e( 'Site key', 'smokyforms' ); ?></label></th>
						<td><input type="text" id="smokyforms-site-key" class="regular-text code" name="<?php echo esc_attr( $name ); ?>[captcha_site_key]" value="<?php echo esc_attr( $s['captcha_site_key'] ); ?>" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row"><label for="smokyforms-secret"><?php esc_html_e( 'Secret key', 'smokyforms' ); ?></label></th>
						<td>
							<input type="password" id="smokyforms-secret" class="regular-text code" name="<?php echo esc_attr( $name ); ?>[captcha_secret]" value="" autocomplete="new-password" placeholder="<?php echo $s['captcha_secret'] ? esc_attr__( 'Saved. Leave empty to keep it.', 'smokyforms' ) : ''; ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="smokyforms-min-seconds"><?php esc_html_e( 'Time check', 'smokyforms' ); ?></label></th>
						<td>
							<input type="number" id="smokyforms-min-seconds" class="small-text" min="0" max="60" name="<?php echo esc_attr( $name ); ?>[min_seconds]" value="<?php echo esc_attr( (string) $s['min_seconds'] ); ?>">
							<?php esc_html_e( 'seconds', 'smokyforms' ); ?>
							<p class="description"><?php esc_html_e( 'Forms sent faster than this after the page loaded are treated as spam. 0 switches the check off.', 'smokyforms' ); ?></p>
						</td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Undelivered notifications', 'smokyforms' ); ?></h2>
				<p><?php esc_html_e( 'The free version of SmokyForms sends every entry to you by email and does not save it. SmokyForms Pro (coming soon) saves all entries in your dashboard, with search and CSV export. When a notification email cannot be sent, the entry is kept so it is not lost, and you can send it again under SmokyForms > Undelivered.', 'smokyforms' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="smokyforms-keep"><?php esc_html_e( 'Keep for', 'smokyforms' ); ?></label></th>
						<td>
							<input type="number" id="smokyforms-keep" class="small-text" min="1" max="365" name="<?php echo esc_attr( $name ); ?>[keep_undelivered]" value="<?php echo esc_attr( (string) $s['keep_undelivered'] ); ?>">
							<?php esc_html_e( 'days', 'smokyforms' ); ?>
						</td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Uploaded files', 'smokyforms' ); ?></h2>
				<p><?php esc_html_e( 'Files and signatures sent with a form are saved in the folder uploads/smokyforms with hard-to-guess links, and the email links to them.', 'smokyforms' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="smokyforms-keep-files"><?php esc_html_e( 'Delete uploaded files after', 'smokyforms' ); ?></label></th>
						<td>
							<input type="number" id="smokyforms-keep-files" class="small-text" min="0" max="3650" name="<?php echo esc_attr( $name ); ?>[keep_files]" value="<?php echo esc_attr( (string) $s['keep_files'] ); ?>">
							<?php esc_html_e( 'days', 'smokyforms' ); ?>
							<p class="description"><?php esc_html_e( '0 keeps the files until you delete them yourself.', 'smokyforms' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
