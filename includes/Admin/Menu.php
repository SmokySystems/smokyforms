<?php
/**
 * Admin menu and form list.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms\Admin;

use SmokySystems\SmokyForms\Forms;
use SmokySystems\SmokyForms\Undelivered;

defined( 'ABSPATH' ) || exit;

/**
 * SmokyForms menu, the form list (the post type's own list screen) and the links into the builder.
 */
final class Menu {

	const PARENT = 'edit.php?post_type=smokyforms_form';

	/**
	 * Capability that manages forms and settings.
	 *
	 * @return string
	 */
	public static function cap() {
		return (string) apply_filters( 'smokyforms_capability', 'manage_options' );
	}

	/**
	 * Registers hooks.
	 */
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'register_post_type_args', array( __CLASS__, 'caps' ), 10, 2 );
		add_filter( 'get_edit_post_link', array( __CLASS__, 'edit_link' ), 10, 2 );
		add_action( 'load-post-new.php', array( __CLASS__, 'redirect_new' ) );
		add_action( 'load-post.php', array( __CLASS__, 'redirect_edit' ) );
		add_filter( 'manage_' . Forms::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Forms::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_smokyforms_duplicate', array( __CLASS__, 'duplicate' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SMOKYFORMS_FILE ), array( __CLASS__, 'plugin_links' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'list_assets' ) );
	}

	/**
	 * Only users with the SmokyForms capability can manage forms.
	 *
	 * @param array  $args Post type arguments.
	 * @param string $type Post type.
	 * @return array
	 */
	public static function caps( $args, $type ) {
		if ( Forms::POST_TYPE !== $type ) {
			return $args;
		}
		$cap                  = self::cap();
		$args['capabilities'] = array(
			'create_posts'           => $cap,
			'edit_posts'             => $cap,
			'edit_others_posts'      => $cap,
			'edit_published_posts'   => $cap,
			'edit_private_posts'     => $cap,
			'publish_posts'          => $cap,
			'read_private_posts'     => $cap,
			'delete_posts'           => $cap,
			'delete_others_posts'    => $cap,
			'delete_published_posts' => $cap,
			'delete_private_posts'   => $cap,
		);
		return $args;
	}

	/**
	 * Menu entries.
	 */
	public static function menu() {
		$cap   = self::cap();
		$count = current_user_can( $cap ) ? Undelivered::count() : 0;
		$icon  = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="93.75 99.4 562.5 562.5"><path fill="black" d="M 93.98 460.21 L 203.69 460.21 C 205.29 475.89 210.71 487.84 219.96 496.07 C 229.20 504.32 241.26 508.44 256.13 508.44 C 271.40 508.44 283.45 504.92 292.29 497.88 C 301.13 490.85 305.55 481.11 305.55 468.66 C 305.55 458.21 302.03 449.57 295.00 442.73 C 287.97 435.91 279.33 430.28 269.08 425.85 C 258.83 421.43 244.27 416.41 225.39 410.78 C 198.06 402.35 175.76 393.91 158.48 385.47 C 141.20 377.03 126.33 364.57 113.87 348.10 C 101.42 331.62 95.19 310.13 95.19 283.61 C 95.19 244.22 109.45 213.38 137.98 191.08 C 166.51 168.78 203.69 157.62 249.50 157.62 C 296.11 157.62 333.68 168.78 362.21 191.08 C 390.74 213.38 406.01 244.42 408.02 284.21 L 296.51 284.21 C 295.71 270.54 290.69 259.79 281.44 251.96 C 272.20 244.12 260.35 240.21 245.87 240.21 C 233.42 240.21 223.37 243.52 215.74 250.15 C 208.10 256.78 204.29 266.32 204.29 278.78 C 204.29 292.44 210.72 303.09 223.58 310.73 C 236.43 318.36 256.53 326.60 283.85 335.44 C 311.18 344.69 333.38 353.53 350.46 361.96 C 367.53 370.40 382.30 382.66 394.76 398.73 C 407.22 414.81 413.45 435.50 413.45 460.82 C 413.45 484.93 407.32 506.83 395.06 526.52 C 382.80 546.21 365.02 561.88 341.72 573.53 C 318.41 585.19 290.89 591.01 259.14 591.01 C 228.19 591.01 200.47 585.99 175.96 575.94 C 151.44 565.90 131.85 551.03 117.19 531.34 C 102.52 511.65 94.78 487.94 93.98 460.21 "/><path fill="black" d="M 656.01 163.65 L 656.01 246.23 L 483.62 246.23 L 483.62 335.44 L 612.61 335.44 L 612.61 415.61 L 483.62 415.61 L 483.62 586.80 L 380.55 586.80 L 380.55 562.44 C 390.39 553.78 398.73 543.98 405.53 533.03 C 419.03 511.35 425.78 487.28 425.78 460.82 C 425.78 432.69 418.69 409.47 404.51 391.17 C 397.26 381.81 389.26 373.63 380.55 366.62 L 380.55 296.55 L 408.02 296.55 C 408.23 296.55 408.44 296.54 408.64 296.53 C 409.45 296.49 410.25 296.37 411.03 296.17 C 411.82 295.97 412.58 295.70 413.31 295.35 C 414.04 295.00 414.73 294.59 415.38 294.11 C 416.03 293.62 416.63 293.08 417.17 292.48 C 417.71 291.88 418.19 291.23 418.61 290.54 C 419.03 289.84 419.37 289.11 419.64 288.35 C 419.91 287.58 420.11 286.80 420.23 286.00 C 420.34 285.20 420.38 284.39 420.34 283.58 C 418.41 245.29 405.14 214.32 380.55 190.66 L 380.55 163.65 Z M 656.01 163.65 "/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		add_menu_page( 'SmokyForms', 'SmokyForms', $cap, self::PARENT, '', $icon, 30 );
		add_submenu_page( self::PARENT, __( 'All Forms', 'smokyforms' ), __( 'All Forms', 'smokyforms' ), $cap, self::PARENT );
		add_submenu_page( self::PARENT, __( 'Add New Form', 'smokyforms' ), __( 'Add New Form', 'smokyforms' ), $cap, Builder::SLUG, array( Builder::class, 'page' ) );
		add_submenu_page( self::PARENT, __( 'Settings', 'smokyforms' ), __( 'Settings', 'smokyforms' ), $cap, Settings::SLUG, array( Settings::class, 'page' ) );
		$label = __( 'Undelivered', 'smokyforms' );
		if ( $count ) {
			$label .= ' <span class="awaiting-mod"><span class="pending-count">' . (int) $count . '</span></span>';
		}
		add_submenu_page( self::PARENT, __( 'Undelivered', 'smokyforms' ), $label, $cap, UndeliveredPage::SLUG, array( UndeliveredPage::class, 'page' ) );
	}

	/**
	 * Builder URL of a form.
	 *
	 * @param int $id Form id (0 for a new form).
	 * @return string
	 */
	public static function builder_url( $id = 0 ) {
		$args = array(
			'post_type' => Forms::POST_TYPE,
			'page'      => Builder::SLUG,
		);
		if ( $id ) {
			$args['form'] = (int) $id;
		}
		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * "Edit" opens the builder.
	 *
	 * @param string $link Link.
	 * @param int    $id   Post id.
	 * @return string
	 */
	public static function edit_link( $link, $id ) {
		return Forms::POST_TYPE === get_post_type( $id ) ? self::builder_url( $id ) : $link;
	}

	/**
	 * post-new.php for forms goes to the builder.
	 */
	public static function redirect_new() {
		if ( isset( $_GET['post_type'] ) && Forms::POST_TYPE === $_GET['post_type'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( self::builder_url() );
			exit;
		}
	}

	/**
	 * post.php?action=edit for forms goes to the builder.
	 */
	public static function redirect_edit() {
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id && Forms::POST_TYPE === get_post_type( $id ) && isset( $_GET['action'] ) && 'edit' === $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( self::builder_url( $id ) );
			exit;
		}
	}

	/**
	 * List columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		return array(
			'cb'                   => $columns['cb'],
			'title'                => __( 'Form', 'smokyforms' ),
			'smokyforms_shortcode' => __( 'Shortcode', 'smokyforms' ),
			'smokyforms_fields'    => __( 'Fields', 'smokyforms' ),
			'date'                 => $columns['date'],
		);
	}

	/**
	 * List column content.
	 *
	 * @param string $column Column.
	 * @param int    $id     Post id.
	 */
	public static function column( $column, $id ) {
		if ( 'smokyforms_shortcode' === $column ) {
			echo '<input type="text" readonly class="smokyforms-shortcode code" value="' . esc_attr( '[smokyforms id="' . $id . '"]' ) . '" aria-label="' . esc_attr__( 'Shortcode', 'smokyforms' ) . '">';
		} elseif ( 'smokyforms_fields' === $column ) {
			$form = Forms::get( $id );
			echo esc_html( number_format_i18n( $form ? count( $form['fields'] ) : 0 ) );
		}
	}

	/**
	 * Row actions: Edit opens the builder, Duplicate copies the form; no Quick Edit.
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( Forms::POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'] );
		if ( 'trash' !== $post->post_status ) {
			$url                  = wp_nonce_url( admin_url( 'admin-post.php?action=smokyforms_duplicate&form=' . $post->ID ), 'smokyforms_duplicate_' . $post->ID );
			$actions['duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Duplicate', 'smokyforms' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Copies a form.
	 */
	public static function duplicate() {
		$id = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0;
		check_admin_referer( 'smokyforms_duplicate_' . $id );
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'smokyforms' ) );
		}
		$form = Forms::get( $id );
		if ( $form ) {
			/* translators: %s: name of the copied form */
			Forms::save( 0, sprintf( __( '%s (copy)', 'smokyforms' ), $form['title'] ), $form );
		}
		wp_safe_redirect( admin_url( self::PARENT ) );
		exit;
	}

	/**
	 * Links on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function plugin_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( self::PARENT ) ) . '">' . esc_html__( 'Forms', 'smokyforms' ) . '</a>',
			'<a href="' . esc_url( admin_url( self::PARENT . '&page=' . Settings::SLUG ) ) . '">' . esc_html__( 'Settings', 'smokyforms' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Selects the shortcode on click in the form list.
	 *
	 * @param string $hook Screen hook.
	 */
	public static function list_assets( $hook ) {
		$screen = get_current_screen();
		if ( 'edit.php' === $hook && $screen && Forms::POST_TYPE === $screen->post_type ) {
			wp_add_inline_script( 'common', 'document.addEventListener("click",function(e){if(e.target.classList&&e.target.classList.contains("smokyforms-shortcode")){e.target.select();}});' );
			wp_add_inline_style( 'common', '.smokyforms-shortcode{width:100%;max-width:220px}' );
		}
	}
}
