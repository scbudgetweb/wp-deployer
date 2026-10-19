<?php
/**
 * What can be pushed. Post types are detected as they are registered, and each one is credited to the plugin
 * or theme whose code registered it, so the settings page can group them ("LiveCanvas: Partials, Sections…").
 * Besides post types there are a few site-level sources: menus, Additional CSS, theme settings, the compiled
 * Picostrap CSS bundle and the homepage / posts page setting.
 *
 * Every pushable object gets a stable UUID (post or term meta `_wpd_uuid`) so it can be matched on the other
 * site, where its numeric ID is different. Site-level sources use fixed UUIDs.
 */

defined( 'ABSPATH' ) || exit;

class WPD_Sources {

	const UUID_META = '_wpd_uuid';

	const SINGLETONS = array(
		'custom_css' => 'wpd:custom_css',
		'theme_mods' => 'wpd:theme_mods',
		'css_bundle' => 'wpd:css_bundle',
		'reading'    => 'wpd:reading',
		'options'    => 'wpd:options',
	);

	/** Post types that are never offered: handled elsewhere (media, menus, CSS) or runtime data. */
	const EXCLUDED_TYPES = array(
		'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
		'wp_font_family', 'wp_font_face', 'scheduled-action',
		// Shop data written on the live site: never pushed (Set up new site copies it once).
		'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription', 'wc_user_membership', 'wc_membership_plan',
	);

	const DEFAULT_ON = array(
		'pt:page', 'pt:lc_partial', 'pt:lc_dynamic_template', 'pt:lc_section', 'pt:lc_block', 'pt:forminator_forms',
		'menus', 'custom_css', 'theme_mods', 'css_bundle', 'reading', 'options',
	);

	/** post type => array( 'type' => plugin|theme|mu|core, 'slug' => folder ) */
	private static $origins = array();

	public static function watch_registrations() {
		add_action( 'registered_post_type', array( __CLASS__, 'note_origin' ), 10, 1 );
	}

	public static function note_origin( $post_type ) {
		$plugins = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		$mu      = wp_normalize_path( WPMU_PLUGIN_DIR ) . '/';
		$themes  = wp_normalize_path( get_theme_root() ) . '/';
		$origin  = array( 'type' => 'core', 'slug' => '' );
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			$file = isset( $frame['file'] ) ? wp_normalize_path( $frame['file'] ) : '';
			if ( '' === $file || false !== strpos( $file, '/wp-includes/' ) || false !== strpos( $file, '/wp-admin/' ) ) {
				continue;
			}
			foreach ( array( 'plugin' => $plugins, 'mu' => $mu, 'theme' => $themes ) as $type => $root ) {
				if ( 0 === strpos( $file, $root ) ) {
					$rel    = substr( $file, strlen( $root ) );
					$origin = array( 'type' => $type, 'slug' => strtok( $rel, '/' ) );
					break 2;
				}
			}
		}
		self::$origins[ $post_type ] = $origin;
	}

	public static function default_enabled( $key ) {
		return in_array( $key, self::DEFAULT_ON, true );
	}

	/** Human name of whatever registered a post type. */
	private static function origin_label( $post_type ) {
		$o = isset( self::$origins[ $post_type ] ) ? self::$origins[ $post_type ] : array( 'type' => 'core', 'slug' => '' );
		if ( 'plugin' === $o['type'] ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach ( get_plugins() as $file => $data ) {
				if ( 0 === strpos( $file, $o['slug'] . '/' ) || $file === $o['slug'] ) {
					return $data['Name'];
				}
			}
			return $o['slug'];
		}
		if ( 'theme' === $o['type'] ) {
			$theme = wp_get_theme( $o['slug'] );
			return $theme->exists() ? $theme->get( 'Name' ) . ' (theme)' : $o['slug'];
		}
		if ( 'mu' === $o['type'] ) {
			return 'Must-use plugin: ' . $o['slug'];
		}
		return 'WordPress';
	}

	public static function css_bundle_path() {
		if ( ! function_exists( 'picostrap_get_css_optional_subfolder_name' ) || ! function_exists( 'picostrap_get_complete_css_filename' ) ) {
			return null;
		}
		return get_stylesheet_directory() . '/' . picostrap_get_css_optional_subfolder_name() . picostrap_get_complete_css_filename();
	}

	/**
	 * Every source this site could push, grouped by provider.
	 *
	 * @return array[] each: key, group, label, description, count, available
	 */
	public static function all() {
		$out = array();
		foreach ( get_post_types( array(), 'objects' ) as $name => $obj ) {
			if ( in_array( $name, self::EXCLUDED_TYPES, true ) ) {
				continue;
			}
			$out[] = array(
				'key'         => 'pt:' . $name,
				'group'       => self::origin_label( $name ),
				'label'       => $obj->label,
				'description' => $name,
				'count'       => self::count_posts( $name ),
				'available'   => true,
			);
		}
		$menus  = wp_get_nav_menus();
		$bundle = self::css_bundle_path();
		$site   = array(
			array( 'menus', 'Menus', 'Navigation menus, their items and theme locations', count( $menus ), true ),
			array( 'custom_css', 'Additional CSS', 'Customizer › Additional CSS for the active theme', '' !== trim( wp_get_custom_css() ) ? 1 : 0, true ),
			array( 'theme_mods', 'Theme settings', 'Customizer values matching the theme setting patterns below (design tokens, fonts…)', 1, true ),
			array( 'css_bundle', 'Compiled theme CSS', 'Picostrap css-output bundle, so token changes show without recompiling', $bundle && is_file( $bundle ) ? 1 : 0, (bool) $bundle ),
			array( 'reading', 'Homepage & posts page', 'Settings › Reading: what the front page and the posts page are', 1, true ),
			array( 'options', 'Plugin settings', 'Only the options named under "Plugin settings that are pushed" below', count( WPD_Settings::matching_options( WPD_Settings::option_patterns() ) ), true ),
		);
		foreach ( $site as $s ) {
			$out[] = array(
				'key'         => $s[0],
				'group'       => 'Site design & settings',
				'label'       => $s[1],
				'description' => $s[2],
				'count'       => $s[3],
				'available'   => $s[4],
			);
		}
		return $out;
	}

	private static function count_posts( $post_type ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit')",
			$post_type
		) );
	}

	public static function enabled_post_types() {
		$types = array();
		foreach ( get_post_types() as $name ) {
			if ( ! in_array( $name, self::EXCLUDED_TYPES, true ) && WPD_Settings::source_enabled( 'pt:' . $name ) ) {
				$types[] = $name;
			}
		}
		return $types;
	}

	/**
	 * The pushable items of every enabled source (UUIDs are assigned here on first sight).
	 *
	 * @return array[] each: uuid, kind, source, group, type_label, label, local_id, modified, descriptor
	 */
	public static function list_items() {
		$items = array();
		foreach ( self::enabled_post_types() as $pt ) {
			$obj   = get_post_type_object( $pt );
			$posts = get_posts( array(
				'post_type'      => $pt,
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			) );
			foreach ( $posts as $p ) {
				$items[] = array(
					'uuid'       => self::post_uuid( $p->ID ),
					'kind'       => 'post',
					'source'     => 'pt:' . $pt,
					'group'      => self::origin_label( $pt ),
					'type_label' => $obj->labels->singular_name,
					'label'      => '' !== $p->post_title ? $p->post_title : '(no title) #' . $p->ID,
					'local_id'   => $p->ID,
					'status'     => $p->post_status,
					'modified'   => $p->post_modified,
					'descriptor' => self::post_descriptor( $p ),
				);
			}
		}
		if ( WPD_Settings::source_enabled( 'menus' ) ) {
			foreach ( wp_get_nav_menus() as $menu ) {
				$items[] = array(
					'uuid'       => self::term_uuid( $menu->term_id ),
					'kind'       => 'menu',
					'source'     => 'menus',
					'group'      => 'Site design & settings',
					'type_label' => 'Menu',
					'label'      => $menu->name,
					'local_id'   => $menu->term_id,
					'status'     => '',
					'modified'   => '',
					'descriptor' => array( 'uuid' => self::term_uuid( $menu->term_id ), 'kind' => 'menu', 'slug' => $menu->slug ),
				);
			}
		}
		$labels = array(
			'custom_css' => 'Additional CSS',
			'theme_mods' => 'Theme settings (' . get_stylesheet() . ')',
			'css_bundle' => 'Compiled theme CSS',
			'reading'    => 'Homepage & posts page',
			'options'    => 'Plugin settings (' . implode( ', ', WPD_Settings::matching_options( WPD_Settings::option_patterns() ) ) . ')',
		);
		foreach ( self::SINGLETONS as $key => $uuid ) {
			if ( ! WPD_Settings::source_enabled( $key ) ) {
				continue;
			}
			if ( 'css_bundle' === $key && ! ( self::css_bundle_path() && is_file( self::css_bundle_path() ) ) ) {
				continue;
			}
			if ( 'options' === $key && ! WPD_Settings::matching_options( WPD_Settings::option_patterns() ) ) {
				continue;
			}
			$items[] = array(
				'uuid'       => $uuid,
				'kind'       => $key,
				'source'     => $key,
				'group'      => 'Site design & settings',
				'type_label' => 'Setting',
				'label'      => $labels[ $key ],
				'local_id'   => null,
				'status'     => '',
				'modified'   => 'css_bundle' === $key ? gmdate( 'Y-m-d H:i:s', filemtime( self::css_bundle_path() ) ) : '',
				'descriptor' => array( 'uuid' => $uuid, 'kind' => $key ),
			);
		}
		return $items;
	}

	public static function post_descriptor( WP_Post $p ) {
		$d = array(
			'uuid'      => self::post_uuid( $p->ID ),
			'kind'      => 'attachment' === $p->post_type ? 'attachment' : 'post',
			'post_type' => $p->post_type,
			'slug'      => $p->post_name,
			'title'     => $p->post_title,
		);
		if ( 'attachment' === $p->post_type ) {
			$d['path'] = (string) get_post_meta( $p->ID, '_wp_attached_file', true );
		}
		return $d;
	}

	/* ---------- UUIDs ---------- */

	public static function post_uuid( $post_id ) {
		$uuid = get_post_meta( $post_id, self::UUID_META, true );
		if ( ! $uuid ) {
			$uuid = wp_generate_uuid4();
			update_post_meta( $post_id, self::UUID_META, $uuid );
		}
		return $uuid;
	}

	public static function term_uuid( $term_id ) {
		$uuid = get_term_meta( $term_id, self::UUID_META, true );
		if ( ! $uuid ) {
			$uuid = wp_generate_uuid4();
			update_term_meta( $term_id, self::UUID_META, $uuid );
		}
		return $uuid;
	}

	/** @return array|null array( kind, id ) for a UUID on this site. */
	public static function find_local( $uuid ) {
		$kind = array_search( $uuid, self::SINGLETONS, true );
		if ( false !== $kind ) {
			return array( $kind, null );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT p.ID, p.post_type FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value = %s LIMIT 1",
			self::UUID_META,
			$uuid
		) );
		if ( $row ) {
			return array( 'attachment' === $row->post_type ? 'attachment' : 'post', (int) $row->ID );
		}
		$term_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
			self::UUID_META,
			$uuid
		) );
		return $term_id ? array( 'menu', (int) $term_id ) : null;
	}
}
