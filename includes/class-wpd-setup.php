<?php
/**
 * "Set up new site": turns a fresh WordPress install into a copy of this site, then leaves it linked so later
 * pushes are incremental. Steps, each its own request so slow hosts don't time out and the screen can show
 * progress:
 *
 *   start     target confirms it is fresh, the typed domain matches, and it may install code; a 3-hour
 *             setup window opens
 *   package   one theme or plugin, zipped here, uploaded in chunks, installed there with WordPress's own
 *             upgrader (so DISALLOW_FILE_MODS and filesystem rules apply)
 *   activate  activate the plugins that are active here, and switch to this site's theme
 *   settings  site settings (title, timezone, permalinks…); search engine visibility is left alone
 *   content   a normal push of every enabled item plus the whole media library
 *   finish    delete WordPress's sample post and page (never anything pushed), flush permalinks, close the window
 *
 * Users are not copied: everything created is credited to the administrator the push signs in as.
 */

defined( 'ABSPATH' ) || exit;

class WPD_Setup {

	const FLAG   = 'wpd_setup_running';
	const WINDOW = 3 * HOUR_IN_SECONDS;
	const CHUNK  = 4194304; // 4 MB per request (raw bytes)

	/** Site settings copied by setup (never: site URL, admin email, search engine visibility). */
	const SITE_OPTIONS = array(
		'blogname', 'blogdescription', 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'start_of_week', 'WPLANG',
		'permalink_structure', 'category_base', 'tag_base', 'posts_per_page', 'posts_per_rss', 'rss_use_excerpt',
		'default_comment_status', 'default_ping_status', 'comment_registration', 'require_name_email', 'comment_moderation',
		'comment_previously_approved', 'close_comments_for_old_posts', 'close_comments_days_old', 'thread_comments',
		'thread_comments_depth', 'page_comments', 'comments_per_page', 'default_comments_page', 'comment_order',
		'show_avatars', 'avatar_rating', 'avatar_default', 'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop',
		'medium_size_w', 'medium_size_h', 'medium_large_size_w', 'medium_large_size_h', 'large_size_w', 'large_size_h',
		'uploads_use_yearmonth_folders', 'image_default_link_type', 'image_default_size', 'image_default_align',
	);

	/* ===================================================================== receiving side */

	/** @return array fresh, reasons[], user */
	public static function freshness() {
		global $wpdb;
		$reasons = array();
		if ( WPD_State::all() ) {
			$reasons[] = 'It has already received pushes.';
		}
		$count = function ( $type, $exclude ) use ( $wpdb ) {
			$not = $exclude ? " AND post_name NOT IN ('" . implode( "','", array_map( 'esc_sql', $exclude ) ) . "')" : '';
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit')$not", $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		};
		if ( $n = $count( 'post', array( 'hello-world' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$reasons[] = $n . ' post(s) besides "Hello world!".';
		}
		if ( $n = $count( 'page', array( 'sample-page', 'privacy-policy' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$reasons[] = $n . ' page(s) besides the sample and privacy pages.';
		}
		$media = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
		if ( $media ) {
			$reasons[] = $media . ' item(s) in the media library.';
		}
		$user = wp_get_current_user();
		return array(
			'fresh'       => ! $reasons,
			'reasons'     => $reasons,
			'in_progress' => self::in_progress(),
			'user'        => $user->exists() ? $user->user_login : '',
		);
	}

	public static function in_progress() {
		$flag = get_option( self::FLAG );
		return is_array( $flag ) && $flag['started'] > time() - self::WINDOW;
	}

	/** Why this site can't install code, or '' if it can. */
	public static function install_blocker() {
		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'install_themes' ) || ! current_user_can( 'activate_plugins' ) || ! current_user_can( 'switch_themes' ) ) {
			return 'The signed-in user may not install or activate plugins and themes.';
		}
		if ( ! wp_is_file_mod_allowed( 'wpd_setup' ) ) {
			return 'Installing plugins is disabled on this site (DISALLOW_FILE_MODS).';
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( 'direct' !== get_filesystem_method( array(), WP_PLUGIN_DIR ) ) {
			return 'This server needs FTP credentials to install plugins, so they can\'t be installed remotely. Install the theme and plugins by hand, then use a normal push.';
		}
		return '';
	}

	/** What is installed here (for the sender to skip identical packages). */
	public static function inventory() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[ $file ] = array( 'version' => $data['Version'], 'active' => is_plugin_active( $file ) );
		}
		$themes = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$themes[ $slug ] = $theme->get( 'Version' );
		}
		return array( 'plugins' => $plugins, 'themes' => $themes, 'stylesheet' => get_stylesheet() );
	}

	public static function start( $confirm_host ) {
		$fresh = self::freshness();
		if ( ! $fresh['fresh'] && ! $fresh['in_progress'] ) {
			return new WP_Error( 'wpd_not_fresh', 'This site is not a fresh install: ' . implode( ' ', $fresh['reasons'] ), array( 'status' => 409 ) );
		}
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( strtolower( trim( (string) $confirm_host ) ) !== $host ) {
			return new WP_Error( 'wpd_confirm', 'The domain typed doesn\'t match this site (' . $host . ').', array( 'status' => 400 ) );
		}
		$blocker = self::install_blocker();
		if ( $blocker ) {
			return new WP_Error( 'wpd_cannot_install', $blocker, array( 'status' => 403 ) );
		}
		update_option( self::FLAG, array( 'started' => time(), 'by' => wp_get_current_user()->user_login ), false );
		return array( 'ok' => true, 'inventory' => self::inventory() );
	}

	private static function require_window() {
		return self::in_progress() ? true : new WP_Error( 'wpd_no_setup', 'No site setup is in progress here (it may have timed out). Start again.', array( 'status' => 409 ) );
	}

	/* ---------- chunked uploads (also used for large media files by normal pushes) ---------- */

	private static function tmp_dir() {
		$dir = wp_get_upload_dir()['basedir'] . '/wpd-tmp';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	public static function chunk_path( $upload_id ) {
		if ( ! preg_match( '/^[a-z0-9-]{8,64}$/', (string) $upload_id ) ) {
			return new WP_Error( 'wpd_upload_id', 'Invalid upload id.' );
		}
		return self::tmp_dir() . '/' . $upload_id . '.part';
	}

	public static function receive_chunk( $upload_id, $index, $data_b64 ) {
		$path = self::chunk_path( $upload_id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$bytes = base64_decode( (string) $data_b64, true );
		if ( false === $bytes ) {
			return new WP_Error( 'wpd_chunk', 'Chunk arrived damaged.' );
		}
		// Clear abandoned uploads older than a day.
		foreach ( (array) glob( self::tmp_dir() . '/*.part' ) as $old ) {
			if ( filemtime( $old ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $old );
			}
		}
		$ok = file_put_contents( $path, $bytes, 0 === (int) $index ? 0 : FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false === $ok ? new WP_Error( 'wpd_chunk', 'Could not write to the uploads folder.' ) : array( 'size' => filesize( $path ) );
	}

	/** Take a completed chunked upload: returns its path after checking the checksum. */
	public static function take_upload( $upload_id, $sha1 ) {
		$path = self::chunk_path( $upload_id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		clearstatcache( true, $path );
		if ( ! is_file( $path ) || sha1_file( $path ) !== $sha1 ) {
			wp_delete_file( $path );
			return new WP_Error( 'wpd_hash', 'Upload incomplete or damaged (checksum mismatch).' );
		}
		return $path;
	}

	/* ---------- setup steps ---------- */

	public static function install_package( $type, $upload_id, $sha1 ) {
		$ok = self::require_window();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$blocker = self::install_blocker();
		if ( $blocker ) {
			return new WP_Error( 'wpd_cannot_install', $blocker, array( 'status' => 403 ) );
		}
		$part = self::take_upload( $upload_id, $sha1 );
		if ( is_wp_error( $part ) ) {
			return $part;
		}
		$zip = substr( $part, 0, -5 ) . '.zip';
		rename( $part, $zip );
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = 'theme' === $type ? new Theme_Upgrader( $skin ) : new Plugin_Upgrader( $skin );
		ob_start();
		$result = $upgrader->install( $zip, array( 'overwrite_package' => true, 'clear_update_cache' => false ) );
		ob_end_clean();
		wp_delete_file( $zip );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result || $skin->get_errors()->has_errors() ) {
			$msg = $skin->get_errors()->has_errors() ? $skin->get_error_messages() : 'Installation failed.';
			return new WP_Error( 'wpd_install', wp_strip_all_tags( (string) $msg ), array( 'status' => 500 ) );
		}
		return array( 'installed' => true );
	}

	public static function activate( array $plugins, $stylesheet ) {
		$ok = self::require_window();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$done   = array();
		$failed = array();
		foreach ( $plugins as $file ) {
			if ( is_plugin_active( $file ) ) {
				$done[] = $file;
				continue;
			}
			ob_start();
			$r = activate_plugin( $file );
			ob_end_clean();
			if ( is_wp_error( $r ) ) {
				$failed[ $file ] = $r->get_error_message();
			} else {
				$done[] = $file;
			}
		}
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'wpd_theme', 'Theme ' . $stylesheet . ' is not installed here.', array( 'status' => 500 ) );
		}
		if ( get_stylesheet() !== $stylesheet ) {
			switch_theme( $stylesheet );
		}
		return array( 'activated' => $done, 'failed' => $failed, 'theme' => get_stylesheet() );
	}

	public static function apply_settings( array $options ) {
		$ok = self::require_window();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		global $wp_rewrite;
		$set = array();
		foreach ( $options as $name => $value ) {
			if ( ! in_array( $name, self::SITE_OPTIONS, true ) ) {
				continue;
			}
			if ( 'permalink_structure' === $name ) {
				$wp_rewrite->set_permalink_structure( $value );
			} elseif ( 'category_base' === $name ) {
				$wp_rewrite->set_category_base( $value );
			} elseif ( 'tag_base' === $name ) {
				$wp_rewrite->set_tag_base( $value );
			} else {
				update_option( $name, $value );
			}
			$set[] = $name;
		}
		$lang = isset( $options['WPLANG'] ) ? (string) $options['WPLANG'] : '';
		$pack = null;
		if ( '' !== $lang && 'en_US' !== $lang ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$pack = wp_download_language_pack( $lang );
			if ( $pack ) {
				update_option( 'WPLANG', $pack );
			}
		}
		self::flush_permalinks();
		return array( 'set' => $set, 'language' => '' === $lang ? null : ( $pack ? $pack : 'Could not install the ' . $lang . ' language pack; install it under Settings › General.' ) );
	}

	private static function flush_permalinks() {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		flush_rewrite_rules( true );
	}

	public static function finish() {
		$ok = self::require_window();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$deleted = array();
		foreach ( array( 'post' => 'hello-world', 'page' => 'sample-page' ) as $type => $slug ) {
			$p = get_page_by_path( $slug, OBJECT, $type );
			if ( $p && ! get_post_meta( $p->ID, WPD_Sources::UUID_META, true ) ) {
				wp_delete_post( $p->ID, true );
				$deleted[] = $p->post_title;
			}
		}
		self::flush_permalinks();
		delete_option( self::FLAG );
		return array( 'deleted' => $deleted, 'home' => home_url( '/' ) );
	}

	/* ===================================================================== sending side */

	/** What setup would send, and what the target reports. */
	public static function plan( $env_id ) {
		$remote = WPD_Pusher::handshake( $env_id );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$theirs  = isset( $remote['setup']['inventory'] ) ? $remote['setup']['inventory'] : array( 'plugins' => array(), 'themes' => array() );
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$slug = false !== strpos( $file, '/' ) ? dirname( $file ) : $file;
			if ( WPD_Updater::SLUG === $slug ) {
				continue; // already on the target: it is what receives the push
			}
			$there     = isset( $theirs['plugins'][ $file ] ) ? $theirs['plugins'][ $file ]['version'] : null;
			$plugins[] = array(
				'key'     => 'plugin:' . $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => is_plugin_active( $file ),
				'there'   => $there,
				'size'    => self::dir_size( false !== strpos( $file, '/' ) ? WP_PLUGIN_DIR . '/' . $slug : WP_PLUGIN_DIR . '/' . $file ),
			);
		}
		$themes = array();
		$parent = get_template();
		foreach ( array_unique( array( $parent, get_stylesheet() ) ) as $slug ) {
			$t        = wp_get_theme( $slug );
			$themes[] = array(
				'key'     => 'theme:' . $slug,
				'name'    => $t->get( 'Name' ),
				'version' => $t->get( 'Version' ),
				'role'    => $slug === $parent && get_stylesheet() !== $parent ? 'parent' : 'active',
				'there'   => isset( $theirs['themes'][ $slug ] ) ? $theirs['themes'][ $slug ] : null,
				'size'    => self::dir_size( $t->get_stylesheet_directory() ),
			);
		}
		$users = array();
		foreach ( get_users( array( 'fields' => array( 'user_login', 'display_name' ), 'number' => 50 ) ) as $u ) {
			$users[] = $u->user_login;
		}
		global $wpdb;
		return array(
			'remote'      => $remote,
			'themes'      => $themes,
			'plugins'     => $plugins,
			'users'       => $users,
			'user_count'  => (int) count_users()['total_users'],
			'items'       => count( WPD_Sources::list_items() ),
			'media'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ),
			'host'        => wp_parse_url( $remote['home'], PHP_URL_HOST ),
			'mu_plugins'  => array_keys( get_mu_plugins() ),
		);
	}

	private static function dir_size( $path ) {
		if ( is_file( $path ) ) {
			return filesize( $path );
		}
		$size = 0;
		if ( is_dir( $path ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( false === strpos( $f->getPathname(), '/.git/' ) ) {
					$size += $f->getSize();
				}
			}
		}
		return $size;
	}

	/** Zip a plugin (by plugin file) or theme (by slug) into a temp file, leaving out .git and OS clutter. */
	private static function zip( $key ) {
		list( $type, $id ) = explode( ':', $key, 2 );
		if ( 'theme' === $type ) {
			$theme = wp_get_theme( $id );
			if ( ! $theme->exists() ) {
				return new WP_Error( 'wpd_zip', 'Theme ' . $id . ' not found.' );
			}
			$root = $theme->get_stylesheet_directory();
			$name = $id;
		} else {
			$root = false !== strpos( $id, '/' ) ? WP_PLUGIN_DIR . '/' . dirname( $id ) : WP_PLUGIN_DIR . '/' . $id;
			$name = false !== strpos( $id, '/' ) ? dirname( $id ) : basename( $id, '.php' );
			if ( ! file_exists( $root ) ) {
				return new WP_Error( 'wpd_zip', 'Plugin ' . $id . ' not found.' );
			}
		}
		$tmp = wp_tempnam( 'wpd-' . $name . '.zip' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'wpd_zip', 'Could not create a zip for ' . $name . '.' );
		}
		if ( is_file( $root ) ) {
			$zip->addFile( $root, $name . '/' . basename( $root ) ); // single-file plugin
		} else {
			$base = strlen( rtrim( $root, '/' ) ) + 1;
			$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach ( $it as $f ) {
				$rel = str_replace( '\\', '/', substr( $f->getPathname(), $base ) );
				if ( preg_match( '#(^|/)(\.git|\.DS_Store|__MACOSX)(/|$)#', $rel ) ) {
					continue;
				}
				$f->isDir() ? $zip->addEmptyDir( $name . '/' . $rel ) : $zip->addFile( $f->getPathname(), $name . '/' . $rel );
			}
		}
		$zip->close();
		return $tmp;
	}

	/** Upload a local file to the target in chunks. @return string|WP_Error upload id */
	public static function upload_chunked( WPD_Client $client, $path, $chunk = self::CHUNK ) {
		$id = strtolower( wp_generate_password( 24, false ) );
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$i  = 0;
		while ( ! feof( $fh ) ) {
			$bytes = fread( $fh, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( '' === $bytes && $i > 0 ) {
				break;
			}
			$r = $client->request( 'POST', '/chunk', array( 'upload_id' => $id, 'index' => $i, 'data' => base64_encode( $bytes ) ), 300 );
			if ( is_wp_error( $r ) ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				return $r;
			}
			$i++;
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $id;
	}

	public static function chunk_size( array $remote ) {
		$max = isset( $remote['max_upload'] ) ? (int) $remote['max_upload'] : 0;
		return $max > 0 ? max( 262144, min( self::CHUNK, (int) floor( $max * 0.6 / 1.37 ) ) ) : self::CHUNK;
	}

	/** Run one setup step for the sender's screen. */
	public static function step( $env_id, $step, array $args ) {
		$client = WPD_Client::for_env( $env_id );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 900 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		switch ( $step ) {
			case 'start':
				return $client->request( 'POST', '/setup/start', array( 'confirm' => isset( $args['confirm'] ) ? $args['confirm'] : '' ) );

			case 'package':
				$key = isset( $args['key'] ) ? (string) $args['key'] : '';
				$zip = self::zip( $key );
				if ( is_wp_error( $zip ) ) {
					return $zip;
				}
				$remote = $client->request( 'GET', '/handshake', null, 30 );
				$sha1   = sha1_file( $zip );
				$size   = filesize( $zip );
				$id     = is_wp_error( $remote ) ? $remote : self::upload_chunked( $client, $zip, self::chunk_size( $remote ) );
				wp_delete_file( $zip );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				$r = $client->request( 'POST', '/setup/package', array( 'type' => 0 === strpos( $key, 'theme:' ) ? 'theme' : 'plugin', 'upload_id' => $id, 'sha1' => $sha1 ), 600 );
				return is_wp_error( $r ) ? $r : array_merge( $r, array( 'bytes' => $size ) );

			case 'activate':
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$want    = array_map( 'strval', isset( $args['plugins'] ) ? (array) $args['plugins'] : array() );
				$active  = array_values( array_filter( $want, 'is_plugin_active' ) );
				return $client->request( 'POST', '/setup/activate', array( 'plugins' => $active, 'stylesheet' => get_stylesheet() ), 300 );

			case 'settings':
				$options = array();
				foreach ( self::SITE_OPTIONS as $name ) {
					$options[ $name ] = get_option( $name );
				}
				// The option can be empty while the site still runs in another language (set by a filter or constant).
				$options['WPLANG'] = 'en_US' === get_locale() ? '' : get_locale();
				return $client->request( 'POST', '/setup/settings', array( 'options' => $options ), 120 );

			case 'content':
				global $wpdb;
				$uuids = array_column( WPD_Sources::list_items(), 'uuid' );
				foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'" ) as $att ) {
					$uuids[] = WPD_Sources::post_uuid( (int) $att );
				}
				return WPD_Pusher::push( $env_id, $uuids, false );

			case 'finish':
				return $client->request( 'POST', '/setup/finish', array(), 120 );
		}
		return new WP_Error( 'wpd_step', 'Unknown setup step.', array( 'status' => 400 ) );
	}
}
