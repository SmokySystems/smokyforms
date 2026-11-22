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
		$icon  = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M4 2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm1 4v2h10V6H5zm0 4v2h10v-2H5zm0 4v1.5h6V14H5z"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

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
