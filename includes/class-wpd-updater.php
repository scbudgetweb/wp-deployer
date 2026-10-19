<?php
/**
 * Updates from GitHub releases: the latest release of the repo shows up in Dashboard › Updates and on the
 * Plugins screen like any other plugin update. The release must have a wp-deployer-*.zip asset (the release
 * workflow attaches one).
 *
 * No token is needed for a public repo. A GitHub token (WPD_GITHUB_TOKEN in wp-config.php, or the token field
 * in WP Deployer › Settings) is only needed if the repo is private, or to avoid GitHub's limit of 60
 * unauthenticated API requests per hour per IP address on busy shared hosting.
 */

defined( 'ABSPATH' ) || exit;

class WPD_Updater {

	const REPO      = 'scbudgetweb/wp-deployer';
	const SLUG      = 'wp-deployer';
	const TRANSIENT = 'wpd_update_release';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		add_action( 'upgrader_process_complete', function () {
			delete_site_transient( self::TRANSIENT );
		} );
	}

	public static function basename() {
		return plugin_basename( WPD_FILE );
	}

	public static function token() {
		if ( defined( 'WPD_GITHUB_TOKEN' ) && WPD_GITHUB_TOKEN ) {
			return (string) WPD_GITHUB_TOKEN;
		}
		$s = WPD_Settings::get();
		return isset( $s['github_token_enc'] ) ? WPD_Settings::decrypt( $s['github_token_enc'] ) : '';
	}

	private static function headers( $accept = 'application/vnd.github+json' ) {
		$h     = array( 'Accept' => $accept, 'User-Agent' => 'wp-deployer/' . WPD_VERSION, 'X-GitHub-Api-Version' => '2022-11-28' );
		$token = self::token();
		if ( '' !== $token ) {
			$h['Authorization'] = 'Bearer ' . $token;
		}
		return $h;
	}

	/**
	 * The latest release, cached for 6 hours (30 minutes after an error). "Check again" on Dashboard › Updates
	 * refreshes it.
	 *
	 * @return array|WP_Error version, tag, url, notes, published, package (asset API URL), size
	 */
	public static function latest( $force = false ) {
		$force  = $force || ( is_admin() && isset( $_GET['force-check'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$cached = $force ? false : get_site_transient( self::TRANSIENT );
		if ( false !== $cached ) {
			return isset( $cached['error'] ) ? new WP_Error( 'wpd_update', $cached['error'] ) : $cached;
		}
		$res  = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', array( 'timeout' => 15, 'headers' => self::headers() ) );
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( is_wp_error( $res ) || 200 !== $code || ! is_array( $body ) ) {
			$msg = is_wp_error( $res ) ? $res->get_error_message() : ( 404 === $code ? 'No release found on ' . self::REPO . '. If the repo is private, add a GitHub token (WPD_GITHUB_TOKEN) with read access to it.' : ( 401 === $code ? 'The GitHub token was rejected (expired or revoked?).' : ( 403 === $code || 429 === $code ? 'GitHub rate limit reached for this server; it will retry later (a GitHub token avoids this).' : 'GitHub returned HTTP ' . $code . '.' ) ) );
			set_site_transient( self::TRANSIENT, array( 'error' => $msg ), 30 * MINUTE_IN_SECONDS );
			return new WP_Error( 'wpd_update', $msg );
		}
		$asset = null;
		foreach ( isset( $body['assets'] ) ? (array) $body['assets'] : array() as $a ) {
			if ( preg_match( '/^wp-deployer.*\.zip$/', $a['name'] ) ) {
				$asset = $a;
				break;
			}
		}
		$release = array(
			'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
			'tag'       => $body['tag_name'],
			'url'       => $body['html_url'],
			'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
			'published' => isset( $body['published_at'] ) ? $body['published_at'] : '',
			'package'   => $asset ? $asset['url'] : '',
			'size'      => $asset ? (int) $asset['size'] : 0,
		);
		set_site_transient( self::TRANSIENT, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	/** A git checkout (the development copy) is updated with git, never replaced by a release zip. */
	public static function is_checkout() {
		return file_exists( WPD_DIR . '.git' );
	}

	public static function inject( $transient ) {
		if ( ! is_object( $transient ) || self::is_checkout() ) {
			return $transient;
		}
		$release = self::latest();
		if ( is_wp_error( $release ) || '' === $release['package'] ) {
			return $transient;
		}
		$item = (object) array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'plugin'       => self::basename(),
			'new_version'  => $release['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $release['package'],
			'requires_php' => '7.4',
			'icons'        => array(),
			'banners'      => array(),
		);
		if ( version_compare( $release['version'], WPD_VERSION, '>' ) ) {
			$transient->response[ self::basename() ] = $item;
		} else {
			$transient->no_update[ self::basename() ] = $item; // lets the auto-update toggle show
		}
		return $transient;
	}

	/** "View details" popup. */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::latest();
		if ( is_wp_error( $release ) ) {
			return $result;
		}
		return (object) array(
			'name'          => 'WP Deployer',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => 'Shaun Corness',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires_php'  => '7.4',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => '<h4>' . esc_html( $release['tag'] ) . '</h4>' . wpautop( esc_html( $release['notes'] ) ),
			),
		);
	}

	/**
	 * Download the release asset. Assets of a private repo need the token, and GitHub answers with a redirect
	 * to a signed storage URL that must be fetched without it, so WordPress's own download can't be used.
	 */
	public static function download( $reply, $package, $upgrader ) {
		if ( false !== $reply || 0 !== strpos( (string) $package, 'https://api.github.com/repos/' . self::REPO . '/releases/assets/' ) ) {
			return $reply;
		}
		$res      = wp_remote_get( $package, array( 'timeout' => 30, 'redirection' => 0, 'headers' => self::headers( 'application/octet-stream' ) ) );
		$code     = wp_remote_retrieve_response_code( $res );
		$location = wp_remote_retrieve_header( $res, 'location' );
		if ( is_wp_error( $res ) || ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) || ! $location ) {
			return new WP_Error( 'wpd_download', 'Could not download the WP Deployer update from GitHub' . ( is_wp_error( $res ) ? ': ' . $res->get_error_message() : ' (HTTP ' . $code . ').' ) );
		}
		$tmp = wp_tempnam( 'wp-deployer.zip' );
		$get = wp_remote_get( $location, array( 'timeout' => 300, 'stream' => true, 'filename' => $tmp ) );
		if ( is_wp_error( $get ) || 200 !== wp_remote_retrieve_response_code( $get ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'wpd_download', 'Downloading the WP Deployer update failed' . ( is_wp_error( $get ) ? ': ' . $get->get_error_message() : '.' ) );
		}
		return $tmp;
	}
}
