<?php
/**
 * Database and uploads copy for "Set up new site" (fresh targets only).
 *
 * Every table with this site's prefix is copied, core and plugin alike (WooCommerce, form plugins, anything),
 * except users and usermeta (the target keeps its own: users are never copied) and WP Deployer's own tables.
 * Each table is rebuilt on the target under a temporary name, row batches are run through WPD_Replace (this
 * site's URLs and server paths → the target's, serialized-safe), and when every table is in, all of them are
 * swapped into place in one RENAME. The target's identity options (site URL, search engine visibility, admin
 * email, salts, WP Deployer settings) are kept. Posts are then credited to the target's administrator.
 *
 * The whole uploads folder is copied too (not just files content refers to).
 */

defined( 'ABSPATH' ) || exit;

class WPD_Clone {

	const SKIP_TABLES = array( 'users', 'usermeta', 'wpd_pushes', 'wpd_snapshots' );
	/** Target options that survive the copy (glob patterns). */
	const KEEP_OPTIONS = array(
		'siteurl', 'home', 'blog_public', 'admin_email', 'new_admin_email', 'upload_path', 'upload_url_path',
		'wpd_*', 'auth_key', 'auth_salt', 'secure_auth_key', 'secure_auth_salt', 'logged_in_key', 'logged_in_salt',
		'nonce_key', 'nonce_salt', 'recovery_keys', 'recovery_mode_email_last_sent',
		'using_application_passwords', // without it WordPress ignores application passwords (the push's own login)
	);
	/** Source options never sent. */
	const SKIP_OPTIONS = array( '_transient_*', '_site_transient_*', 'wpd_*' );
	const BATCH_BYTES  = 3000000;
	const BUDGET       = 20; // seconds of work per request from the setup screen

	/* ===================================================================== sending side */

	public static function source_info() {
		global $wpdb;
		return array(
			'prefix' => $wpdb->prefix,
			'urls'   => WPD_Portable::site_urls() + array( 'content' => content_url(), 'plugins' => plugins_url() ),
			'paths'  => WPD_Portable::site_paths(),
			'host'   => wp_parse_url( home_url(), PHP_URL_HOST ),
		);
	}

	/** @return array[] suffix, name, rows, bytes, create */
	public static function tables() {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_A ) as $t ) {
			$suffix = substr( $t['Name'], strlen( $wpdb->prefix ) );
			if ( 'VIEW' === strtoupper( (string) $t['Comment'] ) || in_array( $suffix, self::SKIP_TABLES, true ) || '' === $suffix ) {
				continue;
			}
			if ( is_multisite() && preg_match( '/^\d+_/', $suffix ) ) {
				continue;
			}
			$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . esc_sql( $t['Name'] ) . '`', ARRAY_N );
			$out[]  = array(
				'suffix' => $suffix,
				'name'   => $t['Name'],
				'rows'   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $t['Name'] ) . '`' ),
				'bytes'  => (int) $t['Data_length'],
				'create' => $create ? $create[1] : '',
			);
		}
		return $out;
	}

	private static function primary_key( $table ) {
		global $wpdb;
		$cols = array();
		foreach ( $wpdb->get_results( 'SHOW KEYS FROM `' . esc_sql( $table ) . "` WHERE Key_name = 'PRIMARY'", ARRAY_A ) as $k ) {
			$cols[ (int) $k['Seq_in_index'] ] = $k['Column_name'];
		}
		ksort( $cols );
		return array_values( $cols );
	}

	private static function encode_value( $v ) {
		if ( null === $v ) {
			return null;
		}
		return function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $v, 'UTF-8' ) ? array( 'b64' => base64_encode( $v ) ) : $v;
	}

	/**
	 * Send rows of one table, from $offset, for up to BUDGET seconds.
	 *
	 * @return array|WP_Error offset (next), done, rows (sent this call)
	 */
	public static function send_rows( WPD_Client $client, $suffix, $offset ) {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		$pk    = self::primary_key( $table );
		$order = $pk ? ' ORDER BY ' . implode( ', ', array_map( function ( $c ) {
			return '`' . esc_sql( $c ) . '`';
		}, $pk ) ) : '';
		$start = microtime( true );
		$sent  = 0;
		while ( microtime( true ) - $start < self::BUDGET ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $table ) . '`' . $order . ' LIMIT %d, %d', $offset, 500 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! $rows ) {
				return array( 'offset' => $offset, 'done' => true, 'rows' => $sent );
			}
			$fetched = count( $rows );
			$batch   = array();
			$bytes   = 0;
			$flush   = function () use ( &$batch, &$bytes, $client, $suffix ) {
				if ( ! $batch ) {
					return true;
				}
				$r     = $client->request( 'POST', '/setup/db/rows', array( 'table' => $suffix, 'columns' => array_keys( $batch[0] ), 'rows' => array_map( 'array_values', $batch ) ), 300 );
				$batch = array();
				$bytes = 0;
				return $r;
			};
			foreach ( $rows as $row ) {
				if ( 'options' === $suffix && WPD_Settings::matches( $row['option_name'], self::SKIP_OPTIONS ) ) {
					continue;
				}
				$row = array_map( array( __CLASS__, 'encode_value' ), $row );
				$len = strlen( (string) wp_json_encode( $row ) );
				if ( $batch && $bytes + $len > self::BATCH_BYTES ) {
					$r = $flush();
					if ( is_wp_error( $r ) ) {
						return $r;
					}
				}
				$batch[] = $row;
				$bytes  += $len;
			}
			$r = $flush();
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$offset += $fetched;
			$sent   += $fetched;
			if ( $fetched < 500 ) {
				return array( 'offset' => $offset, 'done' => true, 'rows' => $sent );
			}
		}
		return array( 'offset' => $offset, 'done' => false, 'rows' => $sent );
	}

	/** Every file under uploads (relative paths), minus WP Deployer's temp folder. */
	public static function upload_files() {
		$base  = wp_normalize_path( wp_get_upload_dir()['basedir'] );
		$files = array();
		if ( ! is_dir( $base ) ) {
			return $files;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( ! $f->isFile() ) {
				continue;
			}
			$rel = ltrim( substr( wp_normalize_path( $f->getPathname() ), strlen( $base ) ), '/' );
			if ( 0 === strpos( $rel, 'wpd-tmp/' ) || preg_match( '#(^|/)\.DS_Store$#', $rel ) ) {
				continue;
			}
			$files[ $rel ] = $f->getSize();
		}
		ksort( $files );
		return $files;
	}

	/**
	 * Copy uploads from $cursor for up to BUDGET seconds.
	 *
	 * @return array|WP_Error cursor, total, done, uploaded, skipped[]
	 */
	public static function send_uploads( WPD_Client $client, $cursor ) {
		$all     = array_keys( self::upload_files() );
		$total   = count( $all );
		$base    = wp_get_upload_dir()['basedir'];
		$start   = microtime( true );
		$sent    = 0;
		$skipped = array();
		$remote  = $client->request( 'GET', '/handshake', null, 30 );
		$chunk   = WPD_Setup::chunk_size( is_wp_error( $remote ) ? array() : $remote );
		while ( $cursor < $total && microtime( true ) - $start < self::BUDGET ) {
			$slice  = array_slice( $all, $cursor, 100 );
			$hashes = array();
			foreach ( $slice as $rel ) {
				$hashes[ $rel ] = sha1_file( $base . '/' . $rel );
			}
			$check = $client->request( 'POST', '/files/check', array( 'files' => $hashes ) );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			foreach ( $slice as $rel ) {
				$state = isset( $check['files'][ $rel ] ) ? $check['files'][ $rel ] : 'missing';
				if ( 'invalid' === $state ) {
					$skipped[] = $rel;
				} elseif ( 'same' !== $state ) {
					$abs  = $base . '/' . $rel;
					$body = array( 'path' => $rel, 'sha1' => $hashes[ $rel ], 'overwrite' => true );
					if ( filesize( $abs ) > $chunk ) {
						$id = WPD_Setup::upload_chunked( $client, $abs, $chunk );
						if ( is_wp_error( $id ) ) {
							return $id;
						}
						$body['upload_id'] = $id;
					} else {
						$body['data'] = base64_encode( file_get_contents( $abs ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					}
					$r = $client->request( 'POST', '/files', $body, 300 );
					if ( is_wp_error( $r ) ) {
						$skipped[] = $rel . ' (' . $r->get_error_message() . ')';
					} else {
						$sent++;
					}
				}
				$cursor++;
				if ( microtime( true ) - $start >= self::BUDGET ) {
					break;
				}
			}
		}
		return array( 'cursor' => $cursor, 'total' => $total, 'done' => $cursor >= $total, 'uploaded' => $sent, 'skipped' => $skipped );
	}

	/* ===================================================================== receiving side */

	const STATE = 'wpd_clone';

	private static function temp_name( $suffix ) {
		global $wpdb;
		$name = $wpdb->prefix . 'wpdtmp_' . $suffix;
		return strlen( $name ) > 64 ? $wpdb->prefix . 'wpdtmp_' . substr( md5( $suffix ), 0, 16 ) : $name;
	}

	private static function valid_suffix( $suffix ) {
		return is_string( $suffix ) && preg_match( '/^[A-Za-z0-9_$]{1,60}$/', $suffix );
	}

	/** @param array $tables suffix => CREATE TABLE statement (from the source) */
	public static function begin( array $source, array $tables, $replace_emails ) {
		global $wpdb;
		$created = array();
		$errors  = array();
		foreach ( $tables as $suffix => $create ) {
			if ( ! self::valid_suffix( $suffix ) || in_array( $suffix, self::SKIP_TABLES, true ) ) {
				continue;
			}
			$temp = self::temp_name( $suffix );
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $temp ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL
			$sql = preg_replace( '/^CREATE TABLE `[^`]+`/i', 'CREATE TABLE `' . $temp . '`', (string) $create, 1, $count );
			if ( 1 !== $count || ! preg_match( '/^CREATE TABLE /i', $sql ) ) {
				$errors[ $suffix ] = 'Unexpected table definition.';
				continue;
			}
			$sql = preg_replace( '/\s+AUTO_INCREMENT=\d+/i', '', $sql );
			if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
				// Usually a collation the target's database doesn't know (e.g. MySQL 8's utf8mb4_0900_ai_ci on MariaDB).
				$fallback = preg_replace( '/\s+COLLATE[= ]\s*\w+/i', '', $sql );
				$fallback = preg_replace( '/(DEFAULT\s+)?CHARSET\s*=\s*\w+/i', 'DEFAULT CHARSET=' . $wpdb->charset, $fallback );
				if ( false === $wpdb->query( $fallback ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
					$errors[ $suffix ] = $wpdb->last_error;
					continue;
				}
			}
			$created[] = $suffix;
		}
		update_option( self::STATE, array( 'source' => $source, 'tables' => $created, 'emails' => (bool) $replace_emails, 'errors' => $errors ), false );
		return array( 'created' => count( $created ), 'errors' => $errors );
	}

	/** @return WPD_Replace engine mapping the source site's addresses and paths to this site's */
	private static function engine( array $state ) {
		static $engine = null;
		if ( $engine ) {
			return $engine;
		}
		$src    = $state['source'];
		$engine = ( new WPD_Replace() )->enable_base64();
		$mine   = WPD_Portable::site_urls() + array( 'site' => site_url(), 'content' => content_url(), 'plugins' => plugins_url() );
		$pairs  = array();
		foreach ( (array) $src['urls'] as $name => $url ) {
			$pairs[ $url ] = isset( $mine[ $name ] ) ? $mine[ $name ] : home_url();
		}
		uksort( $pairs, function ( $a, $b ) {
			return strlen( $b ) - strlen( $a ); // longest (most specific) first
		} );
		foreach ( $pairs as $old => $new ) {
			$engine->add_url( $old, WPD_Replace::url_forms( $new ) );
		}
		$paths = WPD_Portable::site_paths();
		foreach ( (array) $src['paths'] as $name => $path ) {
			if ( isset( $paths[ $name ] ) ) {
				$new = untrailingslashit( wp_normalize_path( $paths[ $name ] ) );
				$engine->add_path( $path, array( 'plain' => $new, 'json' => str_replace( '/', '\/', $new ) ) );
			}
		}
		if ( ! empty( $state['emails'] ) && ! empty( $src['host'] ) ) {
			$engine->add_string( '@' . preg_replace( '/^www\./', '', $src['host'] ), '@' . preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		}
		return $engine;
	}

	public static function rows( $suffix, array $columns, array $rows ) {
		global $wpdb;
		$state = get_option( self::STATE );
		if ( ! is_array( $state ) || ! in_array( $suffix, $state['tables'], true ) ) {
			return new WP_Error( 'wpd_clone', 'Table ' . $suffix . ' was not prepared for copying.', array( 'status' => 409 ) );
		}
		foreach ( $columns as $c ) {
			if ( ! is_string( $c ) || ! preg_match( '/^[A-Za-z0-9_$ -]{1,64}$/', $c ) ) {
				return new WP_Error( 'wpd_clone', 'Invalid column name.', array( 'status' => 400 ) );
			}
		}
		$engine     = self::engine( $state );
		$src_prefix = $state['source']['prefix'];
		$name_col   = 'options' === $suffix ? array_search( 'option_name', $columns, true ) : false;
		$values     = array();
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( array_values( (array) $row ) as $n => $v ) {
				if ( is_array( $v ) && isset( $v['b64'] ) ) {
					$v = base64_decode( $v['b64'] );
				} elseif ( is_string( $v ) && '' !== $v && ! is_numeric( $v ) ) {
					$v = $engine->replace( $v );
				}
				if ( false !== $name_col && $n === $name_col && $src_prefix !== $wpdb->prefix && $v === $src_prefix . 'user_roles' ) {
					$v = $wpdb->prefix . 'user_roles';
				}
				$cells[] = null === $v ? 'NULL' : "'" . esc_sql( (string) $v ) . "'";
			}
			$values[] = '(' . implode( ',', $cells ) . ')';
		}
		if ( ! $values ) {
			return array( 'inserted' => 0 );
		}
		$head = 'INSERT INTO `' . esc_sql( self::temp_name( $suffix ) ) . '` (`' . implode( '`,`', $columns ) . '`) VALUES ';
		if ( false !== $wpdb->query( $head . implode( ',', $values ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			return array( 'inserted' => count( $values ) );
		}
		// Retry row by row so one bad row doesn't lose the batch.
		$ok     = 0;
		$errors = array();
		foreach ( $values as $v ) {
			if ( false !== $wpdb->query( $head . $v ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$ok++;
			} elseif ( count( $errors ) < 5 ) {
				$errors[] = $wpdb->last_error;
			}
		}
		return array( 'inserted' => $ok, 'failed' => count( $values ) - $ok, 'errors' => $errors );
	}

	public static function swap() {
		global $wpdb;
		$state = get_option( self::STATE );
		if ( ! is_array( $state ) || empty( $state['tables'] ) ) {
			return new WP_Error( 'wpd_clone', 'Nothing to swap in.', array( 'status' => 409 ) );
		}
		// Keep this site's identity options.
		$keep = array();
		foreach ( $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options}", ARRAY_A ) as $o ) {
			if ( WPD_Settings::matches( $o['option_name'], self::KEEP_OPTIONS ) ) {
				$keep[] = $o;
			}
		}
		$renames = array();
		$old     = array();
		foreach ( $state['tables'] as $suffix ) {
			$real = $wpdb->prefix . $suffix;
			$temp = self::temp_name( $suffix );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $real ) ) ) ) {
				$o         = $wpdb->prefix . 'wpdold_' . substr( md5( $suffix ), 0, 12 );
				$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $o ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL
				$renames[] = '`' . esc_sql( $real ) . '` TO `' . esc_sql( $o ) . '`';
				$old[]     = $o;
			}
			$renames[] = '`' . esc_sql( $temp ) . '` TO `' . esc_sql( $real ) . '`';
		}
		if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			return new WP_Error( 'wpd_clone', 'Swapping the copied tables in failed: ' . $wpdb->last_error, array( 'status' => 500 ) );
		}
		foreach ( $old as $o ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $o ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		foreach ( $keep as $o ) {
			$wpdb->replace( $wpdb->options, $o );
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" );
		wp_cache_flush();
		// Users weren't copied: credit everything to the administrator running the setup.
		$admin   = get_current_user_id();
		$authors = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_author = %d WHERE post_author <> %d", $admin, $admin ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->comments} SET user_id = 0 WHERE user_id <> 0 AND user_id <> %d", $admin ) );
		$state['swapped'] = time();
		update_option( self::STATE, $state, false );
		return array( 'tables' => count( $state['tables'] ), 'authors_remapped' => $authors, 'errors' => $state['errors'] );
	}

	/** Drop leftovers of an interrupted copy. */
	public static function cleanup() {
		global $wpdb;
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wpdtmp_' ) . '%' ) ) as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $t ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		delete_option( self::STATE );
	}
}
