<?php
/**
 * Uploaded files and signatures.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms;

defined( 'ABSPATH' ) || exit;

/**
 * Files are saved under uploads/smokyforms/<year>/<month>/<random>/ only after the whole
 * submission is valid. Direct access to the folder is denied; the e-mail and the entries
 * link to signed addresses that this class serves. The e-mail can attach the files too. They
 * can be deleted automatically after a number of days (Settings).
 */
final class Uploads {

	/**
	 * Base folder and URL.
	 *
	 * @return array{path:string,url:string}
	 */
	public static function base() {
		$u = wp_upload_dir( null, false );
		return array(
			'path' => trailingslashit( $u['basedir'] ) . 'smokyforms',
			'url'  => trailingslashit( $u['baseurl'] ) . 'smokyforms',
		);
	}

	/**
	 * Registers the download address of saved files.
	 */
	public static function boot() {
		add_action( 'admin_post_smokyforms_file', array( __CLASS__, 'serve' ) );
		add_action( 'admin_post_nopriv_smokyforms_file', array( __CLASS__, 'serve' ) );
	}

	/**
	 * Signature of a file path below uploads/smokyforms.
	 *
	 * @param string $rel Path below the base folder.
	 * @return string
	 */
	private static function sign( $rel ) {
		return substr( hash_hmac( 'sha256', 'smokyforms-file|' . $rel, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Signed address of a saved file.
	 *
	 * @param string $rel Path below the base folder.
	 * @return string
	 */
	public static function link( $rel ) {
		return add_query_arg(
			array(
				'action' => 'smokyforms_file',
				'f'      => $rel,
				's'      => self::sign( $rel ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Sends a saved file when the address carries the right signature.
	 */
	public static function serve() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the signature in the address is the check (the links are in e-mails).
		$rel = isset( $_GET['f'] ) ? sanitize_text_field( wp_unslash( $_GET['f'] ) ) : '';
		$sig = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		$base = realpath( self::base()['path'] );
		$path = '' !== $rel && $base ? realpath( $base . '/' . $rel ) : false;
		if ( ! $path || 0 !== strpos( $path, $base . DIRECTORY_SEPARATOR ) || ! is_file( $path ) || ! hash_equals( self::sign( $rel ), $sig ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}
		$type = wp_check_filetype( $path );
		$mime = $type['type'] ? $type['type'] : 'application/octet-stream';
		$show = 0 === strpos( $mime, 'image/' ) && 'image/svg+xml' !== $mime;
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: ' . ( $show || 'application/pdf' === $mime ? 'inline' : 'attachment' ) . '; filename="' . sanitize_file_name( basename( $path ) ) . '"' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Runs $callback with the WordPress upload functions pointed at one folder.
	 *
	 * @param string   $folder   Folder below the base folder.
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	private static function in_folder( $folder, $callback ) {
		$base   = self::base();
		$filter = static function ( $dirs ) use ( $base, $folder ) {
			$dirs['path']    = $base['path'] . '/' . $folder;
			$dirs['url']     = $base['url'] . '/' . $folder;
			$dirs['subdir']  = '/smokyforms/' . $folder;
			$dirs['basedir'] = $base['path'];
			$dirs['baseurl'] = $base['url'];
			$dirs['error']   = false;
			return $dirs;
		};
		add_filter( 'upload_dir', $filter, 99 );
		try {
			return call_user_func( $callback );
		} finally {
			remove_filter( 'upload_dir', $filter, 99 );
		}
	}

	/**
	 * Creates the base folder with its protection files.
	 *
	 * @return string Base path.
	 */
	private static function prepare() {
		$base = self::base()['path'];
		wp_mkdir_p( $base );
		if ( ! file_exists( $base . '/index.php' ) ) {
			file_put_contents( $base . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$rules = "Options -Indexes\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";
		if ( ! file_exists( $base . '/.htaccess' ) || file_get_contents( $base . '/.htaccess' ) !== $rules ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			file_put_contents( $base . '/.htaccess', $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $base;
	}

	/**
	 * Uploaded files of one field, from $_FILES['smokyforms_files'].
	 *
	 * @param array  $files $_FILES.
	 * @param string $id    Field id.
	 * @return array<int,array> Each: name, type, tmp_name, error, size.
	 */
	public static function from_request( $files, $id ) {
		$all = $files['smokyforms_files'] ?? null;
		if ( ! is_array( $all ) || ! isset( $all['name'][ $id ] ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $all['name'][ $id ] as $i => $name ) {
			$error = (int) ( ( (array) $all['error'][ $id ] )[ $i ] ?? UPLOAD_ERR_NO_FILE );
			if ( UPLOAD_ERR_NO_FILE === $error || '' === (string) $name ) {
				continue;
			}
			$out[] = array(
				'name'     => (string) $name,
				'tmp_name' => (string) ( ( (array) $all['tmp_name'][ $id ] )[ $i ] ?? '' ),
				'error'    => $error,
				'size'     => (int) ( ( (array) $all['size'][ $id ] )[ $i ] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * Mime types allowed for a field (a subset of what WordPress allows).
	 *
	 * @param array $field Upload field.
	 * @return array<string,string>
	 */
	public static function mimes( $field ) {
		$wanted = array_flip( array_filter( array_map( 'trim', explode( ',', $field['extensions'] ) ) ) );
		$out    = array();
		foreach ( get_allowed_mime_types() as $exts => $mime ) {
			$keep = array_intersect( explode( '|', $exts ), array_keys( $wanted ) );
			if ( $keep ) {
				$out[ implode( '|', $keep ) ] = $mime;
			}
		}
		return $out;
	}

	/**
	 * Checks the files of an upload field.
	 *
	 * @param array $field Upload field.
	 * @param array $files Files from from_request().
	 * @return array{0:array,1:string} Files waiting to be saved and the error message.
	 */
	public static function validate( $field, $files ) {
		if ( ! $files ) {
			return array( array(), ! empty( $field['required'] ) ? __( 'This field is required.', 'smokyforms' ) : '' );
		}
		if ( count( $files ) > $field['max_files'] ) {
			/* translators: %s: number of files */
			return array( array(), sprintf( _n( 'You can upload at most %s file.', 'You can upload at most %s files.', $field['max_files'], 'smokyforms' ), number_format_i18n( $field['max_files'] ) ) );
		}
		$mimes   = self::mimes( $field );
		$max     = (float) $field['max_size'] * MB_IN_BYTES;
		$pending = array();
		foreach ( $files as $file ) {
			$name = sanitize_file_name( wp_basename( $file['name'] ) );
			if ( UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
				/* translators: %s: file name */
				return array( array(), sprintf( __( '%s could not be uploaded. Please try again.', 'smokyforms' ), $name ) );
			}
			if ( $file['size'] > $max ) {
				/* translators: 1: file name, 2: size in MB */
				return array( array(), sprintf( __( '%1$s is larger than %2$s MB.', 'smokyforms' ), $name, number_format_i18n( $field['max_size'], floor( $field['max_size'] ) == $field['max_size'] ? 0 : 1 ) ) ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			}
			$check = wp_check_filetype_and_ext( $file['tmp_name'], $name, $mimes );
			if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
				/* translators: %s: file name */
				return array( array(), sprintf( __( '%s: this file type is not allowed.', 'smokyforms' ), $name ) );
			}
			if ( ! empty( $check['proper_filename'] ) ) {
				$name = $check['proper_filename'];
			}
			$pending[] = array(
				'tmp'  => $file['tmp_name'],
				'name' => $name,
				'type' => $check['type'],
				'size' => $file['size'],
			);
		}
		return array( $pending, '' );
	}

	/**
	 * Whether a signature value is a usable PNG image.
	 *
	 * @param string $data Data URL.
	 * @return bool
	 */
	public static function check_signature( $data ) {
		return (bool) self::signature_png( $data );
	}

	/**
	 * PNG bytes of a signature data URL.
	 *
	 * @param string $data Data URL.
	 * @return string|false
	 */
	private static function signature_png( $data ) {
		if ( strlen( $data ) > 600000 || ! preg_match( '#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $data, $m ) ) {
			return false;
		}
		$png = base64_decode( $m[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $png ) {
			return false;
		}
		$info = @getimagesizefromstring( $png ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || IMAGETYPE_PNG !== $info[2] || $info[0] > 3000 || $info[1] > 1500 ) {
			return false;
		}
		return $png;
	}

	/**
	 * Saves the files of a valid submission and puts their details into the values.
	 *
	 * @param array $form    Form.
	 * @param array $values  Values by field id.
	 * @param array $pending Files waiting per upload field id.
	 * @return array Values; file and signature fields become lists of name, url and file (path below uploads/smokyforms).
	 */
	public static function store( $form, $values, $pending ) {
		$folder = '';
		$dir    = '';
		$open   = static function () use ( &$folder, &$dir ) {
			if ( '' === $folder ) {
				$base   = self::prepare();
				$folder = gmdate( 'Y/m' ) . '/' . wp_generate_password( 24, false );
				$dir    = $base . '/' . $folder;
				wp_mkdir_p( $dir );
			}
			return $dir;
		};
		foreach ( $form['fields'] as $f ) {
			if ( 'file' === $f['type'] && array_key_exists( $f['id'], $values ) ) {
				$saved = array();
				foreach ( $pending[ $f['id'] ] ?? array() as $file ) {
					$open();
					require_once ABSPATH . 'wp-admin/includes/file.php';
					$moved = self::in_folder(
						$folder,
						static function () use ( $file ) {
							$upload = array(
								'name'     => $file['name'],
								'type'     => $file['type'] ?? '',
								'tmp_name' => $file['tmp'],
								'error'    => 0,
								'size'     => (int) $file['size'],
							);
							return wp_handle_upload(
								$upload,
								array(
									'test_form' => false,
									'test_type' => false, // The type was checked against the field's list in validate().
								)
							);
						}
					);
					if ( is_array( $moved ) && empty( $moved['error'] ) && ! empty( $moved['file'] ) ) {
						$target  = wp_basename( $moved['file'] );
						$saved[] = array(
							'name' => $file['name'],
							'url'  => self::link( $folder . '/' . $target ),
							'file' => $folder . '/' . $target,
							'size' => (int) $file['size'],
						);
					}
				}
				$values[ $f['id'] ] = $saved;
			}
			if ( 'signature' === $f['type'] && array_key_exists( $f['id'], $values ) ) {
				$png = '' !== $values[ $f['id'] ] ? self::signature_png( (string) $values[ $f['id'] ] ) : false;
				$values[ $f['id'] ] = array();
				if ( $png ) {
					$open();
					$bits = self::in_folder(
						$folder,
						static function () use ( $f, $png ) {
							return wp_upload_bits( 'signature-' . $f['id'] . '.png', null, $png );
						}
					);
					if ( is_array( $bits ) && empty( $bits['error'] ) && ! empty( $bits['file'] ) ) {
						$target             = wp_basename( $bits['file'] );
						$values[ $f['id'] ] = array(
							array(
								'name' => $target,
								'url'  => self::link( $folder . '/' . $target ),
								'file' => $folder . '/' . $target,
								'size' => strlen( $png ),
							),
						);
					}
				}
			}
		}
		return $values;
	}

	/**
	 * Paths of the files to attach to the notification.
	 *
	 * @param array $form   Form.
	 * @param array $values Values.
	 * @return string[]
	 */
	public static function attachments( $form, $values ) {
		$base = self::base()['path'];
		$out  = array();
		foreach ( $form['fields'] as $f ) {
			if ( empty( $f['attach'] ) || ! isset( $values[ $f['id'] ] ) || ! is_array( $values[ $f['id'] ] ) ) {
				continue;
			}
			foreach ( $values[ $f['id'] ] as $file ) {
				if ( ! is_array( $file ) || empty( $file['file'] ) || false !== strpos( $file['file'], '..' ) ) {
					continue;
				}
				$path = $base . '/' . $file['file'];
				if ( is_file( $path ) ) {
					$out[] = $path;
				}
			}
		}
		return $out;
	}

	/**
	 * Daily clean-up: deletes upload folders older than the "Delete uploaded files after" setting.
	 */
	public static function purge() {
		/**
		 * Days to keep uploaded files (0 = keep). SmokyForms Pro returns 0 because stored entries link to their files.
		 *
		 * @param int $days Setting under SmokyForms > Settings.
		 */
		$days = (int) apply_filters( 'smokyforms_keep_files_days', (int) Settings::get( 'keep_files' ) );
		$base = self::base()['path'];
		if ( $days < 1 || ! is_dir( $base ) ) {
			return;
		}
		$limit = time() - $days * DAY_IN_SECONDS;
		foreach ( glob( $base . '/[0-9][0-9][0-9][0-9]/[0-9][0-9]/*', GLOB_ONLYDIR ) ?: array() as $dir ) {
			if ( filemtime( $dir ) < $limit ) {
				foreach ( glob( $dir . '/{,.}*', GLOB_BRACE ) ?: array() as $file ) {
					if ( is_file( $file ) ) {
						wp_delete_file( $file );
					}
				}
				@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}
	}
}
