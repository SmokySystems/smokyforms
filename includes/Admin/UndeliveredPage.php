<?php
/**
 * Screen of the undelivered notifications.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms\Admin;

use SmokySystems\SmokyForms\Fields;
use SmokySystems\SmokyForms\Forms;
use SmokySystems\SmokyForms\Undelivered;

defined( 'ABSPATH' ) || exit;

/**
 * Lists entries whose notification e-mail failed, with Send again and Delete.
 */
final class UndeliveredPage {

	const SLUG = 'smokyforms-undelivered';

	/**
	 * Registers hooks.
	 */
	public static function hooks() {
		add_action( 'admin_post_smokyforms_undelivered', array( __CLASS__, 'action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Warning on the dashboard and the SmokyForms screens while entries wait.
	 */
	public static function notice() {
		if ( ! current_user_can( Menu::cap() ) ) {
			return;
		}
		$screen = get_current_screen();
		$ours   = $screen && ( 'dashboard' === $screen->id || Forms::POST_TYPE === $screen->post_type );
		if ( ! $ours || ( isset( $_GET['page'] ) && self::SLUG === $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$n = Undelivered::count();
		if ( ! $n ) {
			return;
		}
		$url = admin_url( Menu::PARENT . '&page=' . self::SLUG );
		echo '<div class="notice notice-warning"><p>';
		/* translators: %s: number of entries */
		echo esc_html( sprintf( _n( 'SmokyForms: %s entry could not be emailed to you.', 'SmokyForms: %s entries could not be emailed to you.', $n, 'smokyforms' ), number_format_i18n( $n ) ) );
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Show', 'smokyforms' ) . '</a></p></div>';
	}

	/**
	 * Send again or delete.
	 */
	public static function action() {
		check_admin_referer( 'smokyforms_undelivered' );
		if ( ! current_user_can( Menu::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'smokyforms' ) );
		}
		$id   = isset( $_POST['entry'] ) ? absint( $_POST['entry'] ) : 0;
		$what = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		$done = '';
		if ( 'resend' === $what ) {
			$done = Undelivered::resend( $id ) ? 'sent' : 'failed';
		} elseif ( 'delete' === $what ) {
			Undelivered::delete( $id );
			$done = 'deleted';
		}
		wp_safe_redirect( add_query_arg( 'done', $done, admin_url( Menu::PARENT . '&page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Screen.
	 */
	public static function page() {
		if ( ! current_user_can( Menu::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'smokyforms' ) );
		}
		$done     = isset( $_GET['done'] ) ? sanitize_key( $_GET['done'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'sent'    => array( 'success', __( 'The entry was sent and removed from this list.', 'smokyforms' ) ),
			'failed'  => array( 'error', __( 'The entry could still not be sent. Check the email sending settings.', 'smokyforms' ) ),
			'deleted' => array( 'success', __( 'The entry was deleted.', 'smokyforms' ) ),
		);
		$rows     = Undelivered::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Undelivered entries', 'smokyforms' ); ?></h1>
			<p><?php esc_html_e( 'These entries were sent through your forms, but the notification email to you failed. They are kept here so they are not lost, and deleted automatically after the number of days set under Settings.', 'smokyforms' ); ?></p>
			<?php if ( isset( $messages[ $done ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $messages[ $done ][0] ); ?>"><p><?php echo esc_html( $messages[ $done ][1] ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $rows ) : ?>
				<p><strong><?php esc_html_e( 'Nothing here. All notifications were delivered.', 'smokyforms' ); ?></strong></p>
			<?php else : ?>
				<p><a href="<?php echo esc_url( admin_url( Menu::PARENT . '&page=' . Settings::SLUG . '&tab=email' ) ); ?>"><?php esc_html_e( 'Check the email sending settings', 'smokyforms' ); ?></a></p>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$form   = Forms::get( (int) $row->form_id );
					$values = json_decode( $row->data, true );
					$values = is_array( $values ) ? $values : array();
					$date   = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row->created . ' UTC' ) );
					?>
					<details class="card" style="max-width:none;margin-bottom:12px;">
						<summary style="cursor:pointer;">
							<strong><?php echo esc_html( $form ? $form['title'] : __( 'Deleted form', 'smokyforms' ) ); ?></strong>
							&middot; <?php echo esc_html( $date ); ?>
							&middot; <?php echo esc_html( $row->recipients ); ?>
						</summary>
						<p><strong><?php esc_html_e( 'Reason:', 'smokyforms' ); ?></strong> <?php echo esc_html( $row->error ); ?></p>
						<table class="widefat striped" style="margin-bottom:12px;">
							<tbody>
							<?php
							foreach ( $values as $key => $value ) {
								if ( '_' === substr( (string) $key, 0, 1 ) ) {
									continue;
								}
								$field = $form ? Forms::field( $form, $key ) : null;
								$label = $field && '' !== $field['label'] ? $field['label'] : $key;
								$html  = $field ? Fields::display_html( $field, $value ) : esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
								echo '<tr><th style="width:30%;">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- display_html() escapes.
							}
							?>
							</tbody>
						</table>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-inline-end:8px;">
							<?php wp_nonce_field( 'smokyforms_undelivered' ); ?>
							<input type="hidden" name="action" value="smokyforms_undelivered">
							<input type="hidden" name="entry" value="<?php echo esc_attr( (string) $row->id ); ?>">
							<button type="submit" name="do" value="resend" class="button button-primary" <?php disabled( ! $form ); ?>><?php esc_html_e( 'Send again', 'smokyforms' ); ?></button>
						</form>
						<details style="display:inline-block;">
							<summary class="button"><?php esc_html_e( 'Delete', 'smokyforms' ); ?></summary>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
								<?php wp_nonce_field( 'smokyforms_undelivered' ); ?>
								<input type="hidden" name="action" value="smokyforms_undelivered">
								<input type="hidden" name="entry" value="<?php echo esc_attr( (string) $row->id ); ?>">
								<span><?php esc_html_e( 'Delete this entry for good?', 'smokyforms' ); ?></span>
								<button type="submit" name="do" value="delete" class="button button-link-delete"><?php esc_html_e( 'Yes, delete', 'smokyforms' ); ?></button>
							</form>
						</details>
					</details>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
