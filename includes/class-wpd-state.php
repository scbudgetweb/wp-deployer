<?php
/**
 * Receiving-side bookkeeping, one entry per pushed UUID (option `wpd_state`):
 *   source_hash  hash of the item as the sender last pushed it (compared with the sender's current hash → "changed locally")
 *   local_hash   hash of this site's copy straight after that push (compared with its current hash → "edited here since")
 *   meta_keys    the post meta keys that push wrote; only these are compared, and only these are removed if the sender drops them
 *   pushed_at, push_id
 */

defined( 'ABSPATH' ) || exit;

class WPD_State {

	const OPTION = 'wpd_state';

	public static function all() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	public static function get( $uuid ) {
		$all = self::all();
		return isset( $all[ $uuid ] ) ? $all[ $uuid ] : null;
	}

	public static function set( $uuid, $entry ) {
		$all = self::all();
		if ( null === $entry ) {
			unset( $all[ $uuid ] );
		} else {
			$all[ $uuid ] = $entry;
		}
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Hash of this site's current copy, over the same fields a push writes.
	 *
	 * @param string   $kind
	 * @param int|null $id        post / term ID (null for site-level items)
	 * @param array    $meta_keys post meta keys to include
	 * @param array    $patterns  name patterns (theme_mods and options only)
	 */
	public static function current_hash( $kind, $id, array $meta_keys = array(), array $patterns = array() ) {
		switch ( $kind ) {
			case 'post':
			case 'attachment':
				$p = get_post( $id );
				if ( ! $p ) {
					return '';
				}
				$meta = array();
				foreach ( $meta_keys as $key ) {
					$meta[ $key ] = get_post_meta( $id, $key, false );
				}
				$fields = 'attachment' === $kind
					? array( $p->post_title, $p->post_excerpt, $p->post_content, get_post_meta( $id, '_wp_attached_file', true ) )
					: array( $p->post_title, $p->post_name, $p->post_content, $p->post_excerpt, $p->post_status, (int) $p->menu_order, (int) $p->post_parent );
				return WPD_Portable::hash( array( $fields, $meta ) );
			case 'menu':
				$menu = wp_get_nav_menu_object( $id );
				if ( ! $menu ) {
					return '';
				}
				$items = (array) wp_get_nav_menu_items( $id );
				$pos   = array();
				foreach ( $items as $n => $i ) {
					$pos[ $i->ID ] = $n;
				}
				$norm = array( $menu->name );
				foreach ( $items as $i ) {
					$norm[] = array(
						$i->post_title, $i->type, $i->object, (int) $i->object_id, 'custom' === $i->type ? $i->url : '',
						isset( $pos[ $i->menu_item_parent ] ) ? $pos[ $i->menu_item_parent ] : -1,
						$i->target, trim( implode( ' ', (array) $i->classes ) ),
					);
				}
				return WPD_Portable::hash( $norm );
			case 'custom_css':
				return md5( wp_get_custom_css() );
			case 'theme_mods':
				$mods = array();
				foreach ( (array) get_theme_mods() as $k => $v ) {
					if ( is_string( $k ) && ! in_array( $k, WPD_Exporter::SKIP_MODS, true ) && WPD_Settings::matches( $k, $patterns ) ) {
						$mods[ $k ] = $v;
					}
				}
				return WPD_Portable::hash( $mods );
			case 'css_bundle':
				$path = WPD_Sources::css_bundle_path();
				return $path && is_file( $path ) ? md5_file( $path ) : '';
			case 'reading':
				return WPD_Portable::hash( array( get_option( 'show_on_front' ), (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ) );
			case 'options':
				$values = array();
				foreach ( WPD_Settings::matching_options( $patterns ) as $name ) {
					$values[ $name ] = get_option( $name );
				}
				return WPD_Portable::hash( $values );
		}
		return '';
	}
}
