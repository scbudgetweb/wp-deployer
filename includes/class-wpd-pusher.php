<?php
/**
 * Sending side: compares this site with a push target, and runs a push.
 *
 * A push = the selected items + everything they need that the target lacks: referenced images and upload files
 * always travel (unchanged ones are skipped cheaply), and referenced posts of enabled sources are added when
 * the target does not have them yet (e.g. a page embedding a form or a section). Files go first, one request
 * each, then the items in one request that the target applies atomically.
 */

defined( 'ABSPATH' ) || exit;

class WPD_Pusher {

	const STATUS_LABELS = array(
		'new'           => 'New',
		'changed'       => 'Changed here',
		'synced'        => 'In sync',
		'remote_edited' => 'Edited on target',
		'conflict'      => 'Changed on both',
		'unlinked'      => 'Exists on target, never pushed',
		'blocked'       => 'Blocked',
	);

	/** Mark items as pushed on a target that already has them (after Set up new site copied the database). */
	public static function link( $env_id, array $uuids ) {
		$client = WPD_Client::for_env( $env_id );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$exporter = new WPD_Exporter();
		$items    = array();
		foreach ( $uuids as $uuid ) {
			$item = $exporter->export_uuid( $uuid );
			if ( $item ) {
				$items[] = $item;
			}
		}
		return $client->request( 'POST', '/import', array( 'link' => true, 'items' => $items, 'source' => array( 'home' => home_url(), 'name' => get_bloginfo( 'name' ) ) ), 300 );
	}

	public static function handshake( $env_id ) {
		$client = WPD_Client::for_env( $env_id );
		return is_wp_error( $client ) ? $client : $client->request( 'GET', '/handshake', null, 30 );
	}

	public static function status( $env_id ) {
		$client = WPD_Client::for_env( $env_id );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$remote = $client->request( 'GET', '/handshake', null, 30 );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}
		$rows     = WPD_Sources::list_items();
		$exporter = new WPD_Exporter();
		$hashes   = array();
		foreach ( $rows as $row ) {
			$item                   = $exporter->export_uuid( $row['uuid'] );
			$hashes[ $row['uuid'] ] = $item ? $item['hash'] : '';
		}
		$manifest = $client->request( 'POST', '/manifest', array(
			'descriptors' => array_column( $rows, 'descriptor' ),
			'patterns'    => array( 'theme_mods' => WPD_Settings::theme_mod_patterns(), 'options' => WPD_Settings::option_patterns() ),
		) );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		foreach ( $rows as &$row ) {
			$m      = isset( $manifest['items'][ $row['uuid'] ] ) ? $manifest['items'][ $row['uuid'] ] : array( 'found' => false );
			$status = 'new';
			if ( ! empty( $m['conflict'] ) ) {
				$status = 'blocked';
			} elseif ( ! empty( $m['found'] ) ) {
				if ( null === $m['source_hash'] ) {
					$status = 'unlinked';
				} else {
					$changed = $m['source_hash'] !== $hashes[ $row['uuid'] ];
					$edited  = ! empty( $m['remote_edited'] );
					$status  = $changed && $edited ? 'conflict' : ( $edited ? 'remote_edited' : ( $changed ? 'changed' : 'synced' ) );
				}
			}
			$row['sync']         = $status;
			$row['sync_label']   = self::STATUS_LABELS[ $status ];
			$row['note']         = isset( $m['conflict'] ) ? $m['conflict'] : '';
			$row['pushed_at']    = isset( $m['pushed_at'] ) ? $m['pushed_at'] : null;
			$row['remote_link']  = isset( $m['edit_link'] ) ? $m['edit_link'] : null;
			$row['edit_link']    = $row['local_id'] && 'post' === $row['kind'] ? get_edit_post_link( $row['local_id'], 'raw' ) : null;
			unset( $row['descriptor'] );
		}
		unset( $row );
		return array( 'remote' => $remote, 'items' => $rows, 'local_stylesheet' => get_stylesheet() );
	}

	/**
	 * @param string   $env_id
	 * @param string[] $uuids   selected items
	 * @param bool     $dry_run
	 * @param string[] $force   uuids allowed to overwrite edits made on the target
	 * @param string   $phase   'all'; or, from the Push screen, 'files' (upload for up to 20s, repeat until
	 *                          remaining is 0) then 'import'
	 */
	public static function push( $env_id, array $uuids, $dry_run, array $force = array(), $phase = 'all' ) {
		$client = WPD_Client::for_env( $env_id );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$exporter = new WPD_Exporter();
		$items    = array();
		$added    = array();
		foreach ( $uuids as $uuid ) {
			$item = $exporter->export_uuid( $uuid );
			if ( $item ) {
				$items[ $uuid ] = $item;
			}
		}

		// Pull in dependencies until nothing new turns up.
		$p = $exporter->portable;
		for ( $round = 0; $round < 6; $round++ ) {
			$new = false;
			foreach ( array_keys( $p->media ) as $att_id ) {
				$uuid = WPD_Sources::post_uuid( $att_id );
				if ( ! isset( $items[ $uuid ] ) && ( $item = $exporter->export_attachment( $att_id ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
					$items[ $uuid ] = $item;
					$new            = true;
				}
			}
			$candidates = array();
			foreach ( $p->refs as $uuid => $d ) {
				if ( 'post' === $d['kind'] && ! isset( $items[ $uuid ] ) && WPD_Settings::source_enabled( 'pt:' . $d['post_type'] ) ) {
					$candidates[ $uuid ] = $d;
				}
			}
			foreach ( $p->slug_refs as $ref ) {
				$post = get_page_by_path( $ref['slug'], OBJECT, $ref['post_type'] );
				if ( $post && WPD_Settings::source_enabled( 'pt:' . $post->post_type ) ) {
					$d = WPD_Sources::post_descriptor( $post );
					if ( ! isset( $items[ $d['uuid'] ] ) ) {
						$candidates[ $d['uuid'] ] = $d;
					}
				}
			}
			if ( $candidates ) {
				$manifest = $client->request( 'POST', '/manifest', array( 'descriptors' => array_values( $candidates ), 'patterns' => array() ) );
				if ( is_wp_error( $manifest ) ) {
					return $manifest;
				}
				foreach ( $candidates as $uuid => $d ) {
					if ( empty( $manifest['items'][ $uuid ]['found'] ) && ( $item = $exporter->export_uuid( $uuid ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
						$items[ $uuid ] = $item;
						$added[]        = $item['label'] . ' (' . $d['post_type'] . ')';
						$new            = true;
					}
				}
			}
			if ( ! $new ) {
				break;
			}
		}

		// Files: every attachment's files plus loose upload files referenced by URL.
		$basedir = wp_get_upload_dir()['basedir'];
		$files   = array();
		$owner   = array();
		foreach ( $items as $uuid => $item ) {
			if ( 'attachment' === $item['kind'] ) {
				foreach ( $item['data']['files'] as $rel => $sha1 ) {
					$files[ $rel ] = $sha1;
					$owner[ $rel ] = $uuid;
				}
			}
		}
		foreach ( array_keys( $p->files ) as $rel ) {
			if ( ! isset( $files[ $rel ] ) ) {
				$files[ $rel ] = sha1_file( $basedir . '/' . $rel );
			}
		}
		$file_notes = array();
		$uploads    = array();
		if ( $files ) {
			$check = $client->request( 'POST', '/files/check', array( 'files' => $files ) );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$att_descs = array();
			foreach ( $items as $uuid => $item ) {
				if ( 'attachment' === $item['kind'] ) {
					$att_descs[] = $item['descriptor'];
				}
			}
			$att_found = $att_descs ? $client->request( 'POST', '/manifest', array( 'descriptors' => $att_descs, 'patterns' => array() ) ) : array( 'items' => array() );
			if ( is_wp_error( $att_found ) ) {
				return $att_found;
			}
			foreach ( $check['files'] as $rel => $state ) {
				if ( 'missing' === $state ) {
					$uploads[ $rel ] = false;
				} elseif ( 'different' === $state ) {
					$own = isset( $owner[ $rel ] ) ? $owner[ $rel ] : null;
					if ( $own && isset( $att_found['items'][ $own ]['matched_by'] ) && 'uuid' === $att_found['items'][ $own ]['matched_by'] ) {
						$uploads[ $rel ] = true; // same image, replaced here: overwrite
					} else {
						$file_notes[] = 'A different file already exists on ' . $client->name() . ' at uploads/' . $rel . '; not overwritten.';
						if ( $own ) {
							unset( $items[ $own ] );
						}
					}
				} elseif ( 'invalid' === $state ) {
					$file_notes[] = 'uploads/' . $rel . ' is not an allowed file type on ' . $client->name() . '; skipped.';
					if ( isset( $owner[ $rel ] ) ) {
						unset( $items[ $owner[ $rel ] ] );
					}
				}
			}
		}

		if ( ! $dry_run && $uploads && 'import' !== $phase ) {
			$remote  = $client->request( 'GET', '/handshake', null, 30 );
			$chunk   = WPD_Setup::chunk_size( is_wp_error( $remote ) ? array() : $remote );
			$started = microtime( true );
			$done    = 0;
			foreach ( $uploads as $rel => $overwrite ) {
				if ( 'files' === $phase && microtime( true ) - $started > WPD_Clone::BUDGET ) {
					break;
				}
				$done++;
				$abs  = $basedir . '/' . $rel;
				$body = array( 'path' => $rel, 'sha1' => $files[ $rel ], 'overwrite' => $overwrite );
				if ( filesize( $abs ) > $chunk ) {
					$id = WPD_Setup::upload_chunked( $client, $abs, $chunk );
					if ( is_wp_error( $id ) ) {
						$file_notes[] = $id->get_error_message();
						continue;
					}
					$body['upload_id'] = $id;
				} else {
					$body['data'] = base64_encode( file_get_contents( $abs ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
				$r = $client->request( 'POST', '/files', $body, 300 );
				if ( is_wp_error( $r ) ) {
					$file_notes[] = $r->get_error_message();
				}
			}
		}

		if ( 'files' === $phase ) {
			return array( 'phase' => 'files', 'remaining' => $dry_run ? 0 : count( $uploads ) - ( isset( $done ) ? $done : 0 ), 'uploaded' => isset( $done ) ? $done : 0, 'warnings' => $file_notes );
		}

		$in_push = array_flip( array_keys( $items ) );
		$refs    = array();
		foreach ( $p->refs as $uuid => $d ) {
			if ( ! isset( $in_push[ $uuid ] ) ) {
				$refs[] = $d;
			}
		}
		$result = $client->request( 'POST', '/import', array(
			'dry_run' => (bool) $dry_run,
			'items'   => array_values( $items ),
			'refs'    => $refs,
			'force'   => array_values( $force ),
			'source'  => array( 'home' => home_url(), 'name' => get_bloginfo( 'name' ) ),
		), 300 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['added_dependencies'] = $added;
		$result['files']              = array( 'to_upload' => count( $uploads ), 'checked' => count( $files ) );
		$result['warnings']           = array_merge( $file_notes, isset( $result['warnings'] ) ? $result['warnings'] : array() );
		$result['target']             = $client->name();
		return $result;
	}
}
