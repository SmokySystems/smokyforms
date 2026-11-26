<?php
/**
 * Export forms to a JSON file and import them again, on the same or another site.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms;

use SmokySystems\SmokyForms\Admin\Menu;

defined( 'ABSPATH' ) || exit;

/**
 * A file holds one or more form definitions (fields, price tables, settings), never entries.
 * Every form passes through Forms::sanitize() on import, like a form saved in the builder.
 */
final class Transfer {

	const FORMAT    = 'smokyforms-export';
	const MAX_SIZE  = 2097152;
	const MAX_FORMS = 200;

	/**
	 * Registers hooks.
	 */
	public static function hooks() {
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 20, 2 );
		add_filter( 'bulk_actions-edit-' . Forms::POST_TYPE, array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . Forms::POST_TYPE, array( __CLASS__, 'bulk_export' ), 10, 3 );
		add_action( 'admin_post_smokyforms_export', array( __CLASS__, 'export_one' ) );
		add_action( 'admin_post_smokyforms_import', array( __CLASS__, 'import' ) );
		add_action( 'all_admin_notices', array( __CLASS__, 'import_box' ) );
	}

	/**
	 * The export document for a list of forms.
	 *
	 * @param int[] $ids Form ids.
	 * @return array
	 */
	public static function document( array $ids ) {
		$forms = array();
		foreach ( $ids as $id ) {
			$form = Forms::get( $id );
			if ( ! $form ) {
				continue;
			}
			$title = $form['title'];
			unset( $form['id'], $form['title'], $form['status'] );
			$forms[] = array(
				'title' => $title,
				'form'  => $form,
			);
		}
		return array(
			'format'  => self::FORMAT,
			'version' => 1,
			'plugin'  => SMOKYFORMS_VERSION,
			'forms'   => $forms,
		);
	}

	/**
	 * Sends the document as a download and stops.
	 *
	 * @param int[] $ids Form ids.
	 */
	private static function send( array $ids ) {
		$doc  = self::document( $ids );
		$name = 1 === count( $doc['forms'] ) ? sanitize_title( $doc['forms'][0]['title'] ) : 'forms';
		$name = 'smokyforms-' . ( '' !== $name ? $name : 'form' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		echo wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * The Export link in the form list.
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( Forms::POST_TYPE === $post->post_type && 'trash' !== $post->post_status ) {
			$url               = wp_nonce_url( admin_url( 'admin-post.php?action=smokyforms_export&form=' . $post->ID ), 'smokyforms_export_' . $post->ID );
			$actions['export'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Export', 'smokyforms' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Export as a bulk action.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		$actions['smokyforms_export'] = __( 'Export', 'smokyforms' );
		return $actions;
	}

	/**
	 * Handles the bulk action.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param array  $ids      Selected ids.
	 * @return string
	 */
	public static function bulk_export( $redirect, $action, $ids ) {
		if ( 'smokyforms_export' !== $action ) {
			return $redirect;
		}
		if ( ! current_user_can( Menu::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'smokyforms' ) );
		}
		self::send( array_map( 'absint', (array) $ids ) );
		return $redirect;
	}

	/**
	 * One form from the row link.
	 */
	public static function export_one() {
		$id = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0;
		check_admin_referer( 'smokyforms_export_' . $id );
		if ( ! current_user_can( Menu::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'smokyforms' ) );
		}
		self::send( array( $id ) );
	}

	/**
	 * Reads an export document and creates the forms in it.
	 *
	 * @param string $json File content.
	 * @return array{imported: int, skipped: int}|\WP_Error
	 */
	public static function import_json( $json ) {
		$doc = json_decode( (string) $json, true );
		if ( ! is_array( $doc ) || self::FORMAT !== ( $doc['format'] ?? '' ) || ! is_array( $doc['forms'] ?? null ) ) {
			return new \WP_Error( 'smokyforms_format', 'format' );
		}
		$imported = 0;
		$skipped  = 0;
		foreach ( array_slice( $doc['forms'], 0, self::MAX_FORMS ) as $item ) {
			$form  = is_array( $item ) && is_array( $item['form'] ?? null ) ? $item['form'] : null;
			$title = is_array( $item ) && isset( $item['title'] ) && is_string( $item['title'] ) ? trim( $item['title'] ) : '';
			if ( ! $form || empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
				++$skipped;
				continue;
			}
			if ( '' === $title ) {
				$title = __( 'Imported form', 'smokyforms' );
			}
			$id = Forms::save( 0, $title, $form );
			if ( is_wp_error( $id ) ) {
				++$skipped;
			} else {
				++$imported;
			}
		}
		$skipped += max( 0, count( $doc['forms'] ) - self::MAX_FORMS );
		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Handles the upload form.
	 */
	public static function import() {
		check_admin_referer( 'smokyforms_import' );
		if ( ! current_user_can( Menu::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'smokyforms' ) );
		}
		$back = admin_url( Menu::PARENT );
		$file = isset( $_FILES['smokyforms_file'] ) && is_array( $_FILES['smokyforms_file'] ) ? $_FILES['smokyforms_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tmp  = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		$err  = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( in_array( $err, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			wp_safe_redirect( add_query_arg( 'smokyforms_import', 'large', $back ) );
			exit;
		}
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) || UPLOAD_ERR_OK !== $err ) {
			wp_safe_redirect( add_query_arg( 'smokyforms_import', 'nofile', $back ) );
			exit;
		}
		$name = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		if ( (int) filesize( $tmp ) > self::MAX_SIZE ) {
			wp_safe_redirect( add_query_arg( 'smokyforms_import', 'large', $back ) );
			exit;
		}
		if ( 'json' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			wp_safe_redirect( add_query_arg( 'smokyforms_import', 'invalid', $back ) );
			exit;
		}
		$result = self::import_json( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'smokyforms_import', 'invalid', $back ) );
			exit;
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'smokyforms_import'  => 'done',
					'smokyforms_done'    => $result['imported'],
					'smokyforms_skipped' => $result['skipped'],
				),
				$back
			)
		);
		exit;
	}

	/**
	 * Import box and result notice at the top of the form list.
	 */
	public static function import_box() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . Forms::POST_TYPE !== $screen->id || ! current_user_can( Menu::cap() ) ) {
			return;
		}
		$state = isset( $_GET['smokyforms_import'] ) ? sanitize_key( wp_unslash( $_GET['smokyforms_import'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'done' === $state ) {
			$done    = isset( $_GET['smokyforms_done'] ) ? absint( $_GET['smokyforms_done'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$skipped = isset( $_GET['smokyforms_skipped'] ) ? absint( $_GET['smokyforms_skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			/* translators: %d: number of imported forms */
			$text = sprintf( _n( '%d form imported.', '%d forms imported.', $done, 'smokyforms' ), $done );
			if ( $skipped ) {
				/* translators: %d: number of forms that could not be imported */
				$text .= ' ' . sprintf( _n( '%d form was skipped because it had no fields.', '%d forms were skipped because they had no fields.', $skipped, 'smokyforms' ), $skipped );
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
		} elseif ( 'invalid' === $state ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'This is not a SmokyForms export file. Choose a .json file made with Export.', 'smokyforms' ) . '</p></div>';
		} elseif ( 'large' === $state ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The file is too large. An export file is smaller than 2 MB.', 'smokyforms' ) . '</p></div>';
		} elseif ( 'nofile' === $state ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Choose a file to import.', 'smokyforms' ) . '</p></div>';
		}
		?>
		<details class="smokyforms-import" style="margin:12px 0">
			<summary><strong><?php esc_html_e( 'Import forms', 'smokyforms' ); ?></strong></summary>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
				<input type="hidden" name="action" value="smokyforms_import">
				<?php wp_nonce_field( 'smokyforms_import' ); ?>
				<p><?php esc_html_e( 'Choose a .json file made with Export on this or another site. The forms are added as new forms; nothing is overwritten.', 'smokyforms' ); ?></p>
				<input type="file" name="smokyforms_file" accept=".json,application/json">
				<button type="submit" class="button"><?php esc_html_e( 'Import', 'smokyforms' ); ?></button>
			</form>
		</details>
		<?php
	}
}
