<?php
/**
 * Receiving-side history: one row per push, and a snapshot of every object a push changed or created, taken
 * just before the change. Rolling a push back replays its snapshots in reverse (objects it created are deleted).
 * Upload files a push overwrote are not restored.
 */

defined( 'ABSPATH' ) || exit;

class WPD_History {

	const DB_VERSION = '1';

	private static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wpd_' . $name;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::t( 'pushes' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			source varchar(255) NOT NULL DEFAULT '',
			user_login varchar(60) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'running',
			summary longtext,
			PRIMARY KEY  (id)
		) $c;" );
		dbDelta( 'CREATE TABLE ' . self::t( 'snapshots' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			push_id bigint(20) unsigned NOT NULL,
			uuid varchar(64) NOT NULL DEFAULT '',
			kind varchar(20) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			existed tinyint(1) NOT NULL DEFAULT 1,
			label varchar(255) NOT NULL DEFAULT '',
			data longtext,
			PRIMARY KEY  (id),
			KEY push_id (push_id),
			KEY uuid (uuid)
		) $c;" );
		update_option( 'wpd_db_version', self::DB_VERSION, false );
	}

	/* ---------- pushes ---------- */

	public static function start( $source ) {
		global $wpdb;
		$wpdb->insert( self::t( 'pushes' ), array(
			'created_at' => current_time( 'mysql', true ),
			'source'     => substr( (string) $source, 0, 255 ),
			'user_login' => wp_get_current_user()->user_login,
			'status'     => 'running',
		) );
		return (int) $wpdb->insert_id;
	}

	public static function finish( $push_id, $status, array $summary ) {
		global $wpdb;
		$wpdb->update( self::t( 'pushes' ), array( 'status' => $status, 'summary' => wp_json_encode( $summary ) ), array( 'id' => $push_id ) );
	}

	public static function pushes( $limit = 50 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'pushes' ) . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
		foreach ( $rows as &$r ) {
			$r['summary'] = json_decode( (string) $r['summary'], true );
		}
		return $rows;
	}

	public static function push( $push_id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'pushes' ) . ' WHERE id = %d', $push_id ), ARRAY_A );
		if ( $r ) {
			$r['summary'] = json_decode( (string) $r['summary'], true );
		}
		return $r;
	}

	/** Labels of items in this push that later, still-applied pushes changed again. */
	public static function later_overlaps( $push_id ) {
		global $wpdb;
		$s = self::t( 'snapshots' );
		$p = self::t( 'pushes' );
		return $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT a.label FROM $s a JOIN $s b ON b.uuid = a.uuid AND b.push_id > a.push_id JOIN $p pb ON pb.id = b.push_id AND pb.status = 'done' WHERE a.push_id = %d",
			$push_id
		) );
	}

	/* ---------- snapshots ---------- */

	public static function snapshot( $push_id, $uuid, $kind, $object_id, $label ) {
		global $wpdb;
		$existed = null !== $object_id || ! in_array( $kind, array( 'post', 'attachment', 'menu' ), true );
		$data    = array(
			'state'  => WPD_State::get( $uuid ),
			'object' => $existed ? self::capture( $kind, $object_id ) : null,
		);
		$wpdb->insert( self::t( 'snapshots' ), array(
			'push_id'   => $push_id,
			'uuid'      => $uuid,
			'kind'      => $kind,
			'object_id' => (int) $object_id,
			'existed'   => $existed ? 1 : 0,
			'label'     => substr( (string) $label, 0, 255 ),
			'data'      => serialize( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		) );
		return (int) $wpdb->insert_id;
	}

	/** Record the previous values of the options a push is about to write (only those, not every matching option). */
	public static function snapshot_options( $snapshot_id, array $names ) {
		global $wpdb;
		$raw  = $wpdb->get_var( $wpdb->prepare( 'SELECT data FROM ' . self::t( 'snapshots' ) . ' WHERE id = %d', $snapshot_id ) );
		$data = unserialize( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		foreach ( $names as $name ) {
			$missing                              = new stdClass();
			$value                                = get_option( $name, $missing );
			$data['object']['options'][ $name ] = array( 'exists' => $value !== $missing, 'value' => $value !== $missing ? $value : null );
		}
		$wpdb->update( self::t( 'snapshots' ), array( 'data' => serialize( $data ) ), array( 'id' => $snapshot_id ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/** A push created this object: record its new ID so rollback can delete it. */
	public static function set_created_id( $snapshot_id, $object_id ) {
		global $wpdb;
		$wpdb->update( self::t( 'snapshots' ), array( 'object_id' => (int) $object_id ), array( 'id' => $snapshot_id ) );
	}

	private static function capture( $kind, $id ) {
		switch ( $kind ) {
			case 'post':
			case 'attachment':
				$terms = array();
				foreach ( get_object_taxonomies( get_post_type( $id ) ) as $tax ) {
					$ids = wp_get_object_terms( $id, $tax, array( 'fields' => 'ids' ) );
					$terms[ $tax ] = is_wp_error( $ids ) ? array() : $ids;
				}
				return array( 'post' => get_post( $id, ARRAY_A ), 'meta' => get_post_meta( $id ), 'terms' => $terms );
			case 'menu':
				$menu  = wp_get_nav_menu_object( $id );
				$items = array();
				foreach ( (array) wp_get_nav_menu_items( $id ) as $i ) {
					$items[] = array( 'key' => (int) $i->ID, 'parent' => (int) $i->menu_item_parent, 'args' => self::menu_item_args( $i ) );
				}
				return array( 'name' => $menu->name, 'items' => $items, 'locations' => get_nav_menu_locations() );
			case 'custom_css':
				return array( 'css' => wp_get_custom_css() );
			case 'theme_mods':
				return array( 'mods' => get_option( 'theme_mods_' . get_stylesheet() ) );
			case 'css_bundle':
				$path = WPD_Sources::css_bundle_path();
				return array(
					'css'     => $path && is_file( $path ) ? file_get_contents( $path ) : null, // phpcs:ignore WordPress.WP.AlternativeFunctions
					'version' => get_theme_mod( 'css_bundle_version_number' ),
				);
			case 'reading':
				return array(
					'show_on_front'  => get_option( 'show_on_front' ),
					'page_on_front'  => get_option( 'page_on_front' ),
					'page_for_posts' => get_option( 'page_for_posts' ),
				);
			case 'options':
				return array( 'options' => array() ); // filled per option by the importer (see snapshot_options)
		}
		return null;
	}

	public static function menu_item_args( $i ) {
		return array(
			'menu-item-title'       => $i->post_title,
			'menu-item-object'      => $i->object,
			'menu-item-object-id'   => (int) $i->object_id,
			'menu-item-type'        => $i->type,
			'menu-item-url'         => 'custom' === $i->type ? $i->url : '',
			'menu-item-target'      => $i->target,
			'menu-item-classes'     => trim( implode( ' ', (array) $i->classes ) ),
			'menu-item-xfn'         => $i->xfn,
			'menu-item-attr-title'  => $i->attr_title,
			'menu-item-description' => $i->post_content,
			'menu-item-position'    => (int) $i->menu_order,
			'menu-item-status'      => 'publish',
		);
	}

	/** Recreate a menu's items from (key, parent, args) rows, wiring parents by key. */
	public static function rebuild_menu_items( $menu_id, array $rows ) {
		foreach ( (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) ) as $old ) {
			wp_delete_post( $old->ID, true );
		}
		$new = array();
		foreach ( $rows as $r ) {
			$id = wp_update_nav_menu_item( $menu_id, 0, wp_slash( $r['args'] ) );
			if ( ! is_wp_error( $id ) ) {
				$new[ $r['key'] ] = $id;
			}
		}
		foreach ( $rows as $r ) {
			if ( $r['parent'] && isset( $new[ $r['key'] ], $new[ $r['parent'] ] ) ) {
				update_post_meta( $new[ $r['key'] ], '_menu_item_menu_item_parent', (string) $new[ $r['parent'] ] );
			}
		}
	}

	/* ---------- rollback ---------- */

	public static function rollback( $push_id ) {
		global $wpdb;
		$push = self::push( $push_id );
		if ( ! $push || 'done' !== $push['status'] && 'failed' !== $push['status'] ) {
			return new WP_Error( 'wpd_rollback', 'Only completed or failed pushes can be rolled back.' );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'snapshots' ) . ' WHERE push_id = %d ORDER BY id DESC', $push_id ), ARRAY_A );
		kses_remove_filters();
		$restored = array();
		foreach ( $rows as $row ) {
			$data = unserialize( $row['data'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			self::restore( $row['kind'], (int) $row['object_id'], (bool) $row['existed'], $data['object'] );
			WPD_State::set( $row['uuid'], $data['state'] );
			$restored[] = $row['label'];
		}
		WPD_Cache::purge();
		$summary                = is_array( $push['summary'] ) ? $push['summary'] : array();
		$summary['rolled_back'] = gmdate( 'c' );
		self::finish( $push_id, 'rolled_back', $summary );
		return $restored;
	}

	private static function restore( $kind, $id, $existed, $obj ) {
		switch ( $kind ) {
			case 'post':
			case 'attachment':
				if ( ! $existed ) {
					if ( $id && get_post( $id ) ) {
						'attachment' === $kind ? wp_delete_attachment( $id, true ) : wp_delete_post( $id, true );
					}
					return;
				}
				$fields = array_intersect_key( $obj['post'], array_flip( array( 'ID', 'post_title', 'post_name', 'post_content', 'post_excerpt', 'post_status', 'post_parent', 'menu_order', 'post_password', 'comment_status', 'ping_status', 'post_mime_type' ) ) );
				wp_update_post( wp_slash( $fields ) );
				foreach ( array_keys( get_post_meta( $id ) ) as $key ) {
					if ( ! isset( $obj['meta'][ $key ] ) ) {
						delete_post_meta( $id, $key );
					}
				}
				foreach ( $obj['meta'] as $key => $values ) {
					delete_post_meta( $id, $key );
					foreach ( $values as $v ) {
						add_post_meta( $id, $key, wp_slash( maybe_unserialize( $v ) ) );
					}
				}
				foreach ( $obj['terms'] as $tax => $ids ) {
					wp_set_object_terms( $id, array_map( 'intval', $ids ), $tax );
				}
				clean_post_cache( $id );
				return;
			case 'menu':
				if ( ! $existed ) {
					if ( $id && wp_get_nav_menu_object( $id ) ) {
						wp_delete_nav_menu( $id );
					}
					return;
				}
				wp_update_nav_menu_object( $id, array( 'menu-name' => $obj['name'] ) );
				self::rebuild_menu_items( $id, $obj['items'] );
				set_theme_mod( 'nav_menu_locations', $obj['locations'] );
				return;
			case 'custom_css':
				wp_update_custom_css_post( $obj['css'] );
				return;
			case 'theme_mods':
				update_option( 'theme_mods_' . get_stylesheet(), $obj['mods'] );
				return;
			case 'css_bundle':
				$path = WPD_Sources::css_bundle_path();
				if ( $path && null !== $obj['css'] ) {
					file_put_contents( $path, $obj['css'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					$v = get_theme_mod( 'css_bundle_version_number' );
					set_theme_mod( 'css_bundle_version_number', ( is_numeric( $v ) ? (int) $v : 1 ) + 1 );
				}
				return;
			case 'reading':
				foreach ( $obj as $k => $v ) {
					update_option( $k, $v );
				}
				return;
			case 'options':
				foreach ( $obj['options'] as $name => $o ) {
					$o['exists'] ? update_option( $name, $o['value'] ) : delete_option( $name );
				}
				return;
		}
	}
}
