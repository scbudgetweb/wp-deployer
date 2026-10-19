<?php
/**
 * Receiving side: matches incoming items to this site's objects, plans what a push would do, and applies it.
 *
 * Matching: by `_wpd_uuid` first; otherwise (first push) by post type + slug, attachment file path or menu slug.
 * Plan per item: create · update · unchanged (same as last push, not edited here) · skip (edited here since the
 * last push and not forced, a different object already uses that slug, or the active theme differs).
 * Applying snapshots everything first (WPD_History), and rolls the whole push back if anything throws.
 */

defined( 'ABSPATH' ) || exit;

class WPD_Importer {

	const ORDER = array( 'attachment', 'post', 'menu', 'custom_css', 'theme_mods', 'css_bundle', 'reading', 'options' );

	/* ================= matching ================= */

	/**
	 * @param array[] $descriptors each: uuid, kind, and post_type/slug/path for fallback matching
	 * @return array uuid => array( id (int|null), matched_by, conflict )
	 */
	public static function lookup( array $descriptors ) {
		global $wpdb;
		$out   = array();
		$uuids = array();
		foreach ( $descriptors as $d ) {
			if ( isset( WPD_Sources::SINGLETONS[ $d['kind'] ] ) ) {
				$out[ $d['uuid'] ] = array( 'id' => null, 'matched_by' => 'site', 'conflict' => '' );
			} else {
				$uuids[] = $d['uuid'];
			}
		}
		$by_uuid = array();
		foreach ( array_chunk( array_unique( $uuids ), 200 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id AS id, m.meta_value AS uuid FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_wpd_uuid' AND p.post_status <> 'trash' AND m.meta_value IN ($in)", $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			$rows = array_merge( $rows, $wpdb->get_results( $wpdb->prepare( "SELECT term_id AS id, meta_value AS uuid FROM {$wpdb->termmeta} WHERE meta_key = '_wpd_uuid' AND meta_value IN ($in)", $chunk ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $rows as $r ) {
				$by_uuid[ $r->uuid ] = (int) $r->id;
			}
		}
		foreach ( $descriptors as $d ) {
			$uuid = $d['uuid'];
			if ( isset( $out[ $uuid ] ) ) {
				continue;
			}
			if ( isset( $by_uuid[ $uuid ] ) ) {
				$out[ $uuid ] = array( 'id' => $by_uuid[ $uuid ], 'matched_by' => 'uuid', 'conflict' => '' );
				continue;
			}
			$id = null;
			if ( 'attachment' === $d['kind'] && ! empty( $d['path'] ) ) {
				$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $d['path'] ) );
			} elseif ( 'post' === $d['kind'] && ! empty( $d['slug'] ) ) {
				$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND post_status NOT IN ('trash','auto-draft','inherit') ORDER BY ID LIMIT 1", $d['post_type'], $d['slug'] ) );
			} elseif ( 'menu' === $d['kind'] && ! empty( $d['slug'] ) ) {
				$term = get_term_by( 'slug', $d['slug'], 'nav_menu' );
				$id   = $term ? (int) $term->term_id : 0;
			}
			if ( ! $id ) {
				$out[ $uuid ] = array( 'id' => null, 'matched_by' => '', 'conflict' => '' );
				continue;
			}
			$other = 'menu' === $d['kind'] ? get_term_meta( $id, '_wpd_uuid', true ) : get_post_meta( $id, '_wpd_uuid', true );
			$out[ $uuid ] = $other && $other !== $uuid
				? array( 'id' => null, 'matched_by' => '', 'conflict' => 'A different item with the same ' . ( 'attachment' === $d['kind'] ? 'file' : 'slug' ) . ' already exists here (#' . $id . ').' )
				: array( 'id' => $id, 'matched_by' => 'attachment' === $d['kind'] ? 'path' : 'slug', 'conflict' => '' );
		}
		return $out;
	}

	private static function hash_args( $uuid, $kind, array $patterns = array() ) {
		$state = WPD_State::get( $uuid );
		return array(
			$state && isset( $state['meta_keys'] ) ? $state['meta_keys'] : array(),
			$patterns ? $patterns : ( $state && isset( $state['patterns'] ) ? $state['patterns'] : WPD_Settings::theme_mod_patterns() ),
		);
	}

	private static function edited_here( $uuid, $kind, $id, array $patterns = array() ) {
		$state = WPD_State::get( $uuid );
		if ( ! $state ) {
			return false;
		}
		list( $keys, $pats ) = self::hash_args( $uuid, $kind, $patterns );
		return WPD_State::current_hash( $kind, $id, $keys, $pats ) !== $state['local_hash'];
	}

	/**
	 * Status of each described item on this site, for the sender's push screen.
	 *
	 * @param array $patterns kind => name patterns (theme_mods, options)
	 */
	public static function manifest( array $descriptors, array $patterns ) {
		$found = self::lookup( $descriptors );
		$out   = array();
		foreach ( $descriptors as $d ) {
			$uuid   = $d['uuid'];
			$f      = $found[ $uuid ];
			$single = 'site' === $f['matched_by'];
			$exists = $single || null !== $f['id'];
			$state  = $exists ? WPD_State::get( $uuid ) : null;
			$out[ $uuid ] = array(
				'found'         => $exists,
				'matched_by'    => $f['matched_by'],
				'conflict'      => $f['conflict'],
				'remote_id'     => $f['id'],
				'source_hash'   => $state ? $state['source_hash'] : null,
				'pushed_at'     => $state ? $state['pushed_at'] : null,
				'remote_edited' => $exists && $state ? self::edited_here( $uuid, $d['kind'], $f['id'], isset( $patterns[ $d['kind'] ] ) ? (array) $patterns[ $d['kind'] ] : array() ) : false,
				'edit_link'     => $f['id'] && in_array( $d['kind'], array( 'post', 'attachment' ), true ) ? get_edit_post_link( $f['id'], 'raw' ) : null,
			);
		}
		return $out;
	}

	/* ================= files ================= */

	/** @return string|WP_Error absolute path inside uploads for a relative path, if it is a safe, allowed file. */
	public static function upload_path( $rel ) {
		$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
		if ( '' === $rel || false !== strpos( $rel, '..' ) || false !== strpos( $rel, "\0" ) || ! preg_match( '#^[A-Za-z0-9._\-/%@+~ ]+$#', $rel ) ) {
			return new WP_Error( 'wpd_path', 'Invalid upload path: ' . $rel );
		}
		$type = wp_check_filetype( basename( $rel ) );
		if ( ! $type['ext'] ) {
			// While a new site is being set up, copy any file that isn't executable (fonts, JSON, logs, CSS caches…).
			$ext   = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
			$never = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'cgi', 'pl', 'py', 'sh', 'exe', 'htaccess', 'htpasswd', 'ini', 'user.ini' );
			if ( ! WPD_Setup::in_progress() || '' === $ext || in_array( $ext, $never, true ) || 0 === strpos( basename( $rel ), '.' ) ) {
				return new WP_Error( 'wpd_type', 'File type not allowed on this site: ' . $rel );
			}
		}
		return wp_get_upload_dir()['basedir'] . '/' . $rel;
	}

	/** @param array $files rel => sha1  @return array rel => missing|same|different|invalid */
	public static function files_check( array $files ) {
		$out = array();
		foreach ( $files as $rel => $sha1 ) {
			$path = self::upload_path( $rel );
			if ( is_wp_error( $path ) ) {
				$out[ $rel ] = 'invalid';
			} elseif ( ! is_file( $path ) ) {
				$out[ $rel ] = 'missing';
			} else {
				$out[ $rel ] = sha1_file( $path ) === $sha1 ? 'same' : 'different';
			}
		}
		return $out;
	}

	public static function receive_file( $rel, $data_b64, $sha1, $overwrite, $upload_id = '' ) {
		$path = self::upload_path( $rel );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( '' !== $upload_id ) {
			$part = WPD_Setup::take_upload( $upload_id, $sha1 );
			if ( is_wp_error( $part ) ) {
				return $part;
			}
			$bytes = file_get_contents( $part ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_delete_file( $part );
		} else {
			$bytes = base64_decode( (string) $data_b64, true );
		}
		if ( false === $bytes || sha1( $bytes ) !== $sha1 ) {
			return new WP_Error( 'wpd_hash', 'File arrived damaged (checksum mismatch): ' . $rel );
		}
		if ( is_file( $path ) ) {
			if ( sha1_file( $path ) === $sha1 ) {
				return 'same';
			}
			if ( ! $overwrite ) {
				return new WP_Error( 'wpd_exists', 'A different file already exists here: ' . $rel );
			}
		}
		if ( ! wp_mkdir_p( dirname( $path ) ) || false === file_put_contents( $path, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'wpd_write', 'Could not write ' . $rel . ' (check uploads folder permissions).' );
		}
		return 'written';
	}

	/* ================= import ================= */

	/**
	 * @param array $package items[], refs[] (descriptors), force[] (uuids), source{home,name}
	 * @return array|WP_Error plan (dry run) or result
	 */
	public static function import( array $package, $dry_run ) {
		$items = isset( $package['items'] ) ? (array) $package['items'] : array();
		usort( $items, function ( $a, $b ) {
			return array_search( $a['kind'], self::ORDER, true ) - array_search( $b['kind'], self::ORDER, true );
		} );
		$force    = array_flip( isset( $package['force'] ) ? (array) $package['force'] : array() );
		$refs     = isset( $package['refs'] ) ? (array) $package['refs'] : array();
		$descs    = array_merge( array_column( $items, 'descriptor' ), $refs );
		$found    = self::lookup( $descs );
		if ( ! empty( $package['link'] ) ) {
			return self::link( $items, $found );
		}
		$plan     = array();
		$warnings = array();

		foreach ( $items as $item ) {
			$uuid = $item['uuid'];
			$f    = isset( $found[ $uuid ] ) ? $found[ $uuid ] : array( 'id' => null, 'matched_by' => '', 'conflict' => '' );
			$row  = array( 'uuid' => $uuid, 'kind' => $item['kind'], 'label' => $item['label'], 'action' => '', 'note' => '', 'id' => $f['id'] );
			$st   = isset( $item['data']['stylesheet'] ) ? $item['data']['stylesheet'] : null;
			if ( $st && $st !== get_stylesheet() ) {
				$row['action'] = 'skip';
				$row['note']   = 'This site\'s active theme is ' . get_stylesheet() . ', not ' . $st . '.';
			} elseif ( $f['conflict'] ) {
				$row['action'] = 'skip';
				$row['note']   = $f['conflict'];
			} elseif ( 'attachment' === $item['kind'] && ! $dry_run && in_array( 'missing', self::files_check( $item['data']['files'] ), true ) ) {
				$row['action'] = 'skip';
				$row['note']   = 'Its files did not arrive.';
			} elseif ( null === $f['id'] && 'site' !== $f['matched_by'] ) {
				$row['action'] = 'create';
			} else {
				$state    = WPD_State::get( $uuid );
				$patterns = isset( $item['data']['patterns'] ) ? (array) $item['data']['patterns'] : array();
				$edited   = self::edited_here( $uuid, $item['kind'], $f['id'], $patterns );
				if ( $state && $state['source_hash'] === $item['hash'] && ! $edited && ! isset( $force[ $uuid ] ) ) {
					$row['action'] = 'unchanged';
				} elseif ( $edited && ! isset( $force[ $uuid ] ) ) {
					$row['action'] = 'skip';
					$row['note']   = 'Edited on this site since the last push. Tick "overwrite" to replace it.';
				} else {
					$row['action'] = 'update';
					$row['note']   = $state ? ( $edited ? 'Overwrites edits made on this site.' : '' ) : 'Already exists here (matched by ' . $f['matched_by'] . '); this first push replaces it.';
				}
			}
			$plan[ $uuid ] = $row;
		}

		// References that resolve neither to this site nor to an item being created.
		$in_push = array();
		foreach ( $plan as $uuid => $row ) {
			if ( in_array( $row['action'], array( 'create', 'update', 'unchanged' ), true ) ) {
				$in_push[ $uuid ] = true;
			}
		}
		foreach ( $refs as $d ) {
			if ( empty( $found[ $d['uuid'] ]['id'] ) && ! isset( $in_push[ $d['uuid'] ] ) ) {
				$warnings[] = sprintf( 'References %s "%s" (%s), which is not on this site and not in this push; the reference keeps the sending site\'s ID.', 'attachment' === $d['kind'] ? 'image' : 'item', isset( $d['title'] ) ? $d['title'] : $d['uuid'], isset( $d['post_type'] ) ? $d['post_type'] : $d['kind'] );
			}
		}

		if ( $dry_run ) {
			return array( 'dry_run' => true, 'plan' => array_values( $plan ), 'warnings' => $warnings );
		}

		$source  = isset( $package['source']['home'] ) ? $package['source']['home'] : '';
		$push_id = WPD_History::start( $source );
		try {
			kses_remove_filters();
			$map = array();
			foreach ( $found as $uuid => $f ) {
				if ( $f['id'] ) {
					$map[ $uuid ] = $f['id'];
				}
			}
			$by_uuid = array();
			foreach ( $items as $item ) {
				$by_uuid[ $item['uuid'] ] = $item;
			}
			// Phase 1: snapshot, and create placeholders so every new item has an ID before references are resolved.
			foreach ( $plan as $uuid => $row ) {
				if ( 'create' !== $row['action'] && 'update' !== $row['action'] ) {
					continue;
				}
				$item = $by_uuid[ $uuid ];
				$sid  = WPD_History::snapshot( $push_id, $uuid, $item['kind'], $row['id'], $item['label'] );
				if ( 'options' === $item['kind'] ) {
					WPD_History::snapshot_options( $sid, array_keys( (array) $item['data']['options'] ) );
				}
				if ( 'create' === $row['action'] ) {
					$map[ $uuid ] = self::create_placeholder( $item );
					WPD_History::set_created_id( $sid, $map[ $uuid ] );
					$plan[ $uuid ]['id'] = $map[ $uuid ];
				}
				if ( $map[ $uuid ] ?? null ) {
					'menu' === $item['kind'] ? update_term_meta( $map[ $uuid ], '_wpd_uuid', $uuid ) : ( isset( WPD_Sources::SINGLETONS[ $item['kind'] ] ) ? null : update_post_meta( $map[ $uuid ], '_wpd_uuid', $uuid ) );
				}
			}
			// Phase 2: write.
			$unresolved = array();
			foreach ( $plan as $uuid => $row ) {
				if ( 'create' !== $row['action'] && 'update' !== $row['action'] ) {
					continue;
				}
				$item  = $by_uuid[ $uuid ];
				$id    = isset( $map[ $uuid ] ) ? $map[ $uuid ] : null;
				$data  = WPD_Portable::resolve( $item['data'], $map, $unresolved );
				$state = WPD_State::get( $uuid );
				$keys  = self::write( $item['kind'], $id, $data, $state, 'create' === $row['action'] );
				$pats  = isset( $item['data']['patterns'] ) ? (array) $item['data']['patterns'] : array();
				WPD_State::set( $uuid, array(
					'source_hash' => $item['hash'],
					'local_hash'  => WPD_State::current_hash( $item['kind'], $id, $keys, $pats ),
					'meta_keys'   => $keys,
					'patterns'    => $pats,
					'pushed_at'   => gmdate( 'c' ),
					'push_id'     => $push_id,
				) );
			}
			$counts = array_count_values( array_column( $plan, 'action' ) );
			$caches = ! empty( $counts['create'] ) || ! empty( $counts['update'] ) ? WPD_Cache::purge() : array();
			$result = array(
				'caches'   => $caches,
				'push_id'  => $push_id,
				'plan'     => array_values( $plan ),
				'counts'   => $counts,
				'warnings' => $warnings,
				'history'  => admin_url( 'admin.php?page=wpd-history' ),
			);
			WPD_History::finish( $push_id, 'done', array( 'counts' => $counts, 'items' => array_values( $plan ), 'warnings' => $warnings, 'source' => $source ) );
			return $result;
		} catch ( Throwable $e ) {
			WPD_History::finish( $push_id, 'failed', array( 'error' => $e->getMessage(), 'source' => $source ) );
			WPD_History::rollback( $push_id );
			return new WP_Error( 'wpd_import', 'Push failed and was rolled back: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Record items this site already has (copied by Set up new site) as pushed, without changing them.
	 * Only items matched by their UUID are linked.
	 */
	private static function link( array $items, array $found ) {
		$protected = array_merge( WPD_Settings::INTERNAL_META, WPD_Settings::protected_meta() );
		$linked    = 0;
		$missing   = array();
		foreach ( $items as $item ) {
			$f = isset( $found[ $item['uuid'] ] ) ? $found[ $item['uuid'] ] : null;
			if ( ! $f || ( 'site' !== $f['matched_by'] && 'uuid' !== $f['matched_by'] ) ) {
				$missing[] = $item['label'];
				continue;
			}
			$keys = array();
			foreach ( array_keys( isset( $item['data']['meta'] ) ? (array) $item['data']['meta'] : array() ) as $key ) {
				if ( ! WPD_Settings::matches( $key, $protected ) ) {
					$keys[] = $key;
				}
			}
			sort( $keys );
			$pats = isset( $item['data']['patterns'] ) ? (array) $item['data']['patterns'] : array();
			WPD_State::set( $item['uuid'], array(
				'source_hash' => $item['hash'],
				'local_hash'  => WPD_State::current_hash( $item['kind'], $f['id'], $keys, $pats ),
				'meta_keys'   => $keys,
				'patterns'    => $pats,
				'pushed_at'   => gmdate( 'c' ),
				'push_id'     => 0,
			) );
			$linked++;
		}
		return array( 'linked' => $linked, 'missing' => $missing );
	}

	private static function create_placeholder( array $item ) {
		$d = $item['data'];
		switch ( $item['kind'] ) {
			case 'attachment':
				$path = self::upload_path( $d['file'] );
				if ( is_wp_error( $path ) ) {
					throw new Exception( $path->get_error_message() );
				}
				$id = wp_insert_attachment( array(
					'post_title'     => wp_strip_all_tags( $d['post_title'] ),
					'post_mime_type' => $d['post_mime_type'],
					'post_status'    => 'inherit',
					'post_date'      => $d['post_date'],
					'guid'           => wp_get_upload_dir()['baseurl'] . '/' . $d['file'],
				), $path, 0, true );
				break;
			case 'menu':
				$id = wp_create_nav_menu( $d['name'] );
				break;
			default:
				$id = wp_insert_post( array(
					'post_type'   => $d['post_type'],
					'post_title'  => wp_strip_all_tags( (string) $d['post_title'] ),
					'post_name'   => $d['post_name'],
					'post_status' => 'draft',
					'post_date'   => $d['post_date'],
				), true );
		}
		if ( is_wp_error( $id ) ) {
			throw new Exception( $item['label'] . ': ' . $id->get_error_message() );
		}
		return (int) $id;
	}

	/** Apply resolved data. @return string[] the post meta keys written (for posts and attachments). */
	private static function write( $kind, $id, array $d, $state, $created ) {
		switch ( $kind ) {
			case 'post':
				$args = array(
					'ID'             => $id,
					'post_title'     => $d['post_title'],
					'post_name'      => $d['post_name'],
					'post_content'   => $d['post_content'],
					'post_excerpt'   => $d['post_excerpt'],
					'post_status'    => $d['post_status'],
					'post_password'  => $d['post_password'],
					'menu_order'     => (int) $d['menu_order'],
					'comment_status' => $d['comment_status'],
					'ping_status'    => $d['ping_status'],
					'post_parent'    => (int) $d['post_parent'],
				);
				if ( $created ) {
					$args['post_date']     = $d['post_date'];
					$args['post_date_gmt'] = get_gmt_from_date( $d['post_date'] );
					$args['edit_date']     = true;
				}
				$r = wp_update_post( wp_slash( $args ), true );
				if ( is_wp_error( $r ) ) {
					throw new Exception( $d['post_title'] . ': ' . $r->get_error_message() );
				}
				foreach ( (array) $d['terms'] as $tax => $terms ) {
					if ( ! taxonomy_exists( $tax ) ) {
						continue;
					}
					$ids = array();
					foreach ( $terms as $t ) {
						$term = get_term_by( 'slug', $t['slug'], $tax );
						if ( ! $term ) {
							$new  = wp_insert_term( $t['name'], $tax, array( 'slug' => $t['slug'] ) );
							$term = is_wp_error( $new ) ? null : get_term( $new['term_id'], $tax );
						}
						if ( $term ) {
							$ids[] = (int) $term->term_id;
						}
					}
					wp_set_object_terms( $id, $ids, $tax );
				}
				return self::write_meta( $id, (array) $d['meta'], $state );
			case 'attachment':
				wp_update_post( wp_slash( array(
					'ID'           => $id,
					'post_title'   => $d['post_title'],
					'post_excerpt' => $d['post_excerpt'],
					'post_content' => $d['post_content'],
					'post_name'    => $d['post_name'],
				) ) );
				update_post_meta( $id, '_wp_attached_file', $d['file'] );
				wp_update_attachment_metadata( $id, $d['metadata'] );
				return self::write_meta( $id, (array) $d['meta'], $state );
			case 'menu':
				wp_update_nav_menu_object( $id, array( 'menu-name' => $d['name'] ) );
				$rows = array();
				foreach ( $d['items'] as $i ) {
					$type      = $i['type'];
					$object_id = 0;
					if ( 'taxonomy' === $type ) {
						$term = is_array( $i['object_id'] ) ? get_term_by( 'slug', $i['object_id']['slug'], $i['object_id']['taxonomy'] ) : null;
						if ( ! $term ) {
							continue;
						}
						$object_id = (int) $term->term_id;
					} elseif ( 'post_type' === $type ) {
						$object_id = (int) $i['object_id'];
					}
					$rows[] = array(
						'key'    => $i['key'],
						'parent' => $i['parent'],
						'args'   => array(
							'menu-item-title'       => $i['title'],
							'menu-item-object'      => $i['object'],
							'menu-item-object-id'   => $object_id,
							'menu-item-type'        => $type,
							'menu-item-url'         => $i['url'],
							'menu-item-target'      => $i['target'],
							'menu-item-classes'     => $i['classes'],
							'menu-item-xfn'         => $i['xfn'],
							'menu-item-attr-title'  => $i['attr_title'],
							'menu-item-description' => $i['description'],
							'menu-item-position'    => (int) $i['position'],
							'menu-item-status'      => 'publish',
						),
					);
				}
				WPD_History::rebuild_menu_items( $id, $rows );
				$locations = get_nav_menu_locations();
				foreach ( (array) $d['locations'] as $loc ) {
					$locations[ $loc ] = $id;
				}
				set_theme_mod( 'nav_menu_locations', $locations );
				return array();
			case 'custom_css':
				$r = wp_update_custom_css_post( $d['css'] );
				if ( is_wp_error( $r ) ) {
					throw new Exception( 'Additional CSS: ' . $r->get_error_message() );
				}
				return array();
			case 'theme_mods':
				foreach ( (array) get_theme_mods() as $k => $v ) {
					if ( is_string( $k ) && ! array_key_exists( $k, (array) $d['mods'] ) && ! in_array( $k, WPD_Exporter::SKIP_MODS, true ) && WPD_Settings::matches( $k, (array) $d['patterns'] ) ) {
						remove_theme_mod( $k );
					}
				}
				foreach ( (array) $d['mods'] as $k => $v ) {
					set_theme_mod( $k, $v );
				}
				return array();
			case 'css_bundle':
				$path = WPD_Sources::css_bundle_path();
				if ( ! $path ) {
					throw new Exception( 'This site\'s theme has no Picostrap CSS bundle.' );
				}
				if ( ! wp_mkdir_p( dirname( $path ) ) || false === file_put_contents( $path, $d['css'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
					throw new Exception( 'Could not write ' . $path . ' (check theme folder permissions).' );
				}
				$v = get_theme_mod( 'css_bundle_version_number' );
				set_theme_mod( 'css_bundle_version_number', ( is_numeric( $v ) ? (int) $v : 1 ) + 1 );
				return array();
			case 'reading':
				update_option( 'show_on_front', $d['show_on_front'] );
				update_option( 'page_on_front', (int) $d['page_on_front'] );
				update_option( 'page_for_posts', (int) $d['page_for_posts'] );
				return array();
			case 'options':
				foreach ( (array) $d['options'] as $name => $value ) {
					if ( WPD_Settings::matches( $name, WPD_Settings::NEVER_OPTIONS ) ) {
						continue;
					}
					update_option( $name, $value );
				}
				return array();
		}
		throw new Exception( 'Unknown item kind: ' . $kind );
	}

	/** Replace the pushed meta keys; keys this site protects, and keys the sender never pushed, are left alone. */
	private static function write_meta( $id, array $meta, $state ) {
		$protected = array_merge( WPD_Settings::INTERNAL_META, WPD_Settings::protected_meta() );
		$previous  = $state && isset( $state['meta_keys'] ) ? (array) $state['meta_keys'] : array();
		foreach ( $previous as $key ) {
			if ( ! array_key_exists( $key, $meta ) && ! WPD_Settings::matches( $key, $protected ) ) {
				delete_post_meta( $id, $key );
			}
		}
		$written = array();
		foreach ( $meta as $key => $values ) {
			if ( WPD_Settings::matches( $key, $protected ) ) {
				continue;
			}
			delete_post_meta( $id, $key );
			foreach ( (array) $values as $v ) {
				add_post_meta( $id, $key, wp_slash( $v ) );
			}
			$written[] = $key;
		}
		sort( $written );
		return $written;
	}
}
