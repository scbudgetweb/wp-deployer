<?php
/**
 * Builds portable package items from this site's objects. Each item:
 *   uuid, kind (post|attachment|menu|custom_css|theme_mods|css_bundle|reading|options), label, source, descriptor,
 *   data (tokenised, see WPD_Portable), hash (of data; the receiving site stores it as "last pushed").
 */

defined( 'ABSPATH' ) || exit;

class WPD_Exporter {

	/** Theme mods that are never pushed as plain values (handled by menus / per site). */
	const SKIP_MODS = array( 'nav_menu_locations', 'custom_css_post_id', 'css_bundle_version_number', 'sidebars_widgets' );

	/** @var WPD_Portable */
	public $portable;

	public function __construct() {
		$this->portable = new WPD_Portable();
	}

	/** @return array|null */
	public function export_uuid( $uuid ) {
		$found = WPD_Sources::find_local( $uuid );
		if ( ! $found ) {
			return null;
		}
		list( $kind, $id ) = $found;
		switch ( $kind ) {
			case 'post':
				return $this->export_post( $id );
			case 'attachment':
				return $this->export_attachment( $id );
			case 'menu':
				return $this->export_menu( $id );
			default:
				return $this->export_singleton( $kind );
		}
	}

	private function item( $uuid, $kind, $label, $source, array $descriptor, array $data ) {
		return array(
			'uuid'       => $uuid,
			'kind'       => $kind,
			'label'      => $label,
			'source'     => $source,
			'descriptor' => $descriptor,
			'data'       => $data,
			'hash'       => WPD_Portable::hash( $data ),
		);
	}

	public function export_post( $id ) {
		$p = get_post( $id );
		if ( ! $p ) {
			return null;
		}
		$terms = array();
		foreach ( get_object_taxonomies( $p->post_type ) as $tax ) {
			$ts = wp_get_object_terms( $id, $tax );
			if ( ! is_wp_error( $ts ) ) {
				$terms[ $tax ] = array_map( function ( $t ) {
					return array( 'slug' => $t->slug, 'name' => $t->name );
				}, $ts );
			}
		}
		$t    = $this->portable;
		$data = array(
			'post_type'      => $p->post_type,
			'post_name'      => $p->post_name,
			'post_title'     => $t->tokenise( $p->post_title ),
			'post_content'   => $t->tokenise( $p->post_content ),
			'post_excerpt'   => $t->tokenise( $p->post_excerpt ),
			'post_status'    => $p->post_status,
			'post_password'  => $p->post_password,
			'post_date'      => $p->post_date,
			'menu_order'     => (int) $p->menu_order,
			'comment_status' => $p->comment_status,
			'ping_status'    => $p->ping_status,
			'post_parent'    => $p->post_parent ? $t->id_token( $p->post_parent ) : '0',
			'meta'           => $this->export_meta( $id ),
			'terms'          => $terms,
		);
		return $this->item( WPD_Sources::post_uuid( $id ), 'post', $p->post_title, 'pt:' . $p->post_type, WPD_Sources::post_descriptor( $p ), $data );
	}

	public function export_meta( $id ) {
		$skip  = array_merge( WPD_Settings::INTERNAL_META, WPD_Settings::protected_meta() );
		$ids   = WPD_Portable::id_meta_keys();
		$selfs = WPD_Portable::self_id_paths();
		$selfs = isset( $selfs[ get_post_type( $id ) ] ) ? $selfs[ get_post_type( $id ) ] : array();
		$out   = array();
		foreach ( get_post_meta( $id ) as $key => $values ) {
			if ( WPD_Settings::matches( $key, $skip ) ) {
				continue;
			}
			$out[ $key ] = array();
			foreach ( $values as $raw ) {
				$v = maybe_unserialize( $raw );
				if ( isset( $selfs[ $key ] ) && is_array( $v ) ) {
					foreach ( $selfs[ $key ] as $path ) {
						$v = $this->replace_self_id( $v, $path, (int) $id );
					}
				}
				$out[ $key ][] = in_array( $key, $ids, true ) && is_numeric( $v ) ? $this->portable->id_token( $v ) : $this->portable->tokenise( $v );
			}
		}
		ksort( $out );
		return $out;
	}

	/** Swap the post's own ID at a key path for a token (left alone if the path holds something else). */
	private function replace_self_id( array $v, array $path, $id ) {
		$key = array_shift( $path );
		if ( ! array_key_exists( $key, $v ) ) {
			return $v;
		}
		if ( $path ) {
			if ( is_array( $v[ $key ] ) ) {
				$v[ $key ] = $this->replace_self_id( $v[ $key ], $path, $id );
			}
		} elseif ( is_numeric( $v[ $key ] ) && (int) $v[ $key ] === $id ) {
			$v[ $key ] = $this->portable->id_token( $id );
		}
		return $v;
	}

	public function export_attachment( $id ) {
		$p = get_post( $id );
		if ( ! $p || 'attachment' !== $p->post_type ) {
			return null;
		}
		$file     = (string) get_post_meta( $id, '_wp_attached_file', true );
		$metadata = wp_get_attachment_metadata( $id );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$dir      = dirname( $file );
		$dir      = '.' === $dir ? '' : $dir . '/';
		$rels     = array( $file );
		if ( ! empty( $metadata['original_image'] ) ) {
			$rels[] = $dir . $metadata['original_image'];
		}
		foreach ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array() as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$rels[] = $dir . $size['file'];
			}
		}
		$basedir = wp_get_upload_dir()['basedir'];
		$files   = array();
		foreach ( array_unique( $rels ) as $rel ) {
			if ( '' !== $rel && is_file( $basedir . '/' . $rel ) ) {
				$files[ $rel ] = sha1_file( $basedir . '/' . $rel );
			}
		}
		$t    = $this->portable;
		$data = array(
			'post_title'     => $t->tokenise( $p->post_title ),
			'post_excerpt'   => $t->tokenise( $p->post_excerpt ),
			'post_content'   => $t->tokenise( $p->post_content ),
			'post_name'      => $p->post_name,
			'post_mime_type' => $p->post_mime_type,
			'post_date'      => $p->post_date,
			'file'           => $file,
			'metadata'       => $metadata,
			'meta'           => $this->export_meta( $id ),
			'files'          => $files,
		);
		return $this->item( WPD_Sources::post_uuid( $id ), 'attachment', $p->post_title ? $p->post_title : basename( $file ), 'media', WPD_Sources::post_descriptor( $p ), $data );
	}

	public function export_menu( $term_id ) {
		$menu = wp_get_nav_menu_object( $term_id );
		if ( ! $menu ) {
			return null;
		}
		$locations = array();
		foreach ( get_nav_menu_locations() as $loc => $mid ) {
			if ( (int) $mid === (int) $term_id ) {
				$locations[] = $loc;
			}
		}
		$items = array();
		foreach ( (array) wp_get_nav_menu_items( $term_id ) as $i ) {
			$object_id = '0';
			if ( 'post_type' === $i->type ) {
				$object_id = $this->portable->id_token( $i->object_id );
			} elseif ( 'taxonomy' === $i->type ) {
				$term      = get_term( (int) $i->object_id, $i->object );
				$object_id = $term && ! is_wp_error( $term ) ? array( 'taxonomy' => $i->object, 'slug' => $term->slug, 'name' => $term->name ) : '0';
			}
			$items[] = array(
				'key'         => (int) $i->ID,
				'parent'      => (int) $i->menu_item_parent,
				'position'    => (int) $i->menu_order,
				'title'       => $this->portable->tokenise( $i->post_title ),
				'type'        => $i->type,
				'object'      => $i->object,
				'object_id'   => $object_id,
				'url'         => 'custom' === $i->type ? $this->portable->tokenise( $i->url ) : '',
				'target'      => $i->target,
				'attr_title'  => $i->attr_title,
				'description' => $i->post_content,
				'classes'     => trim( implode( ' ', (array) $i->classes ) ),
				'xfn'         => $i->xfn,
			);
		}
		$data = array(
			'name'      => $menu->name,
			'slug'      => $menu->slug,
			'locations' => $locations,
			'items'     => $items,
		);
		$uuid = WPD_Sources::term_uuid( $term_id );
		return $this->item( $uuid, 'menu', $menu->name, 'menus', array( 'uuid' => $uuid, 'kind' => 'menu', 'slug' => $menu->slug ), $data );
	}

	public function export_singleton( $kind ) {
		$t = $this->portable;
		switch ( $kind ) {
			case 'custom_css':
				$data  = array( 'stylesheet' => get_stylesheet(), 'css' => $t->tokenise( wp_get_custom_css() ) );
				$label = 'Additional CSS';
				break;
			case 'theme_mods':
				$patterns = WPD_Settings::theme_mod_patterns();
				$mods     = array();
				foreach ( (array) get_theme_mods() as $k => $v ) {
					if ( is_string( $k ) && ! in_array( $k, self::SKIP_MODS, true ) && WPD_Settings::matches( $k, $patterns ) ) {
						$mods[ $k ] = $t->tokenise( $v );
					}
				}
				ksort( $mods );
				$data  = array( 'stylesheet' => get_stylesheet(), 'patterns' => $patterns, 'mods' => $mods );
				$label = 'Theme settings';
				break;
			case 'css_bundle':
				$path = WPD_Sources::css_bundle_path();
				if ( ! $path || ! is_file( $path ) ) {
					return null;
				}
				$data  = array( 'stylesheet' => get_stylesheet(), 'css' => $t->tokenise( file_get_contents( $path ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$label = 'Compiled theme CSS';
				break;
			case 'reading':
				$front = (int) get_option( 'page_on_front' );
				$posts = (int) get_option( 'page_for_posts' );
				$data  = array(
					'show_on_front'  => get_option( 'show_on_front' ),
					'page_on_front'  => $front ? $t->id_token( $front ) : '0',
					'page_for_posts' => $posts ? $t->id_token( $posts ) : '0',
				);
				$label = 'Homepage & posts page';
				break;
			case 'options':
				$patterns = WPD_Settings::option_patterns();
				$options  = array();
				foreach ( WPD_Settings::matching_options( $patterns ) as $name ) {
					$options[ $name ] = $t->tokenise( get_option( $name ) );
				}
				$data  = array( 'patterns' => $patterns, 'options' => $options );
				$label = 'Plugin settings';
				break;
			default:
				return null;
		}
		$uuid = WPD_Sources::SINGLETONS[ $kind ];
		return $this->item( $uuid, $kind, $label, $kind, array( 'uuid' => $uuid, 'kind' => $kind ), $data );
	}
}
