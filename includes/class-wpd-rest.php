<?php
/**
 * REST routes (namespace wpd/v1).
 *
 * Receiving (called by another site with an application password; need manage_options and "Receive pushes"):
 *   GET  /handshake     site info
 *   POST /manifest      status of described items here
 *   POST /files/check   which upload files are missing or different
 *   POST /files         receive one upload file
 *   POST /import        plan (dry_run) or apply a push
 *
 * Local (this site's own admin screens, cookie + nonce):
 *   GET  /local/status?env=   compare with a target
 *   POST /local/push          { env, uuids[], dry_run, force[] }
 *   POST /local/test          { env }
 */

defined( 'ABSPATH' ) || exit;

class WPD_Rest {

	const NS = 'wpd/v1';

	public static function init() {
		add_filter( 'determine_current_user', array( __CLASS__, 'fallback_auth' ), 25 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** Accept the application password from X-WPD-Auth when the host strips the Authorization header. */
	public static function fallback_auth( $user_id ) {
		if ( $user_id || empty( $_SERVER['HTTP_X_WPD_AUTH'] ) || ! empty( $_SERVER['PHP_AUTH_USER'] ) ) {
			return $user_id;
		}
		$header = wp_unslash( $_SERVER['HTTP_X_WPD_AUTH'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 0 !== stripos( $header, 'basic ' ) ) {
			return $user_id;
		}
		$pair = base64_decode( substr( $header, 6 ), true );
		if ( ! $pair || false === strpos( $pair, ':' ) ) {
			return $user_id;
		}
		list( $user, $pass ) = explode( ':', $pair, 2 );
		$auth                = wp_authenticate_application_password( null, $user, $pass );
		return $auth instanceof WP_User ? $auth->ID : $user_id;
	}

	public static function routes() {
		$receive = array( __CLASS__, 'can_receive' );
		$local   = function () {
			return current_user_can( 'manage_options' );
		};
		$routes  = array(
			array( '/handshake', 'GET', 'handshake', $receive ),
			array( '/manifest', 'POST', 'manifest', $receive ),
			array( '/files/check', 'POST', 'files_check', $receive ),
			array( '/files', 'POST', 'files', $receive ),
			array( '/import', 'POST', 'import', $receive ),
			array( '/local/status', 'GET', 'local_status', $local ),
			array( '/local/push', 'POST', 'local_push', $local ),
			array( '/local/test', 'POST', 'local_test', $local ),
		);
		foreach ( $routes as $r ) {
			register_rest_route( self::NS, $r[0], array(
				'methods'             => $r[1],
				'callback'            => array( __CLASS__, $r[2] ),
				'permission_callback' => $r[3],
			) );
		}
	}

	public static function can_receive() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'wpd_forbidden', 'Not signed in as an administrator of this site.', array( 'status' => 401 ) );
		}
		if ( ! WPD_Settings::receive_enabled() ) {
			return new WP_Error( 'wpd_disabled', 'This site does not accept pushes (WP Deployer › Settings › Receive pushes).', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function handshake() {
		$bundle = WPD_Sources::css_bundle_path();
		return array(
			'name'       => get_bloginfo( 'name' ),
			'home'       => home_url(),
			'wp'         => get_bloginfo( 'version' ),
			'plugin'     => WPD_VERSION,
			'stylesheet' => get_stylesheet(),
			'css_bundle' => $bundle && is_file( $bundle ),
			'history'    => admin_url( 'admin.php?page=wpd-history' ),
			'max_upload' => wp_max_upload_size(),
		);
	}

	public static function manifest( WP_REST_Request $req ) {
		$descs = array_values( array_filter( (array) $req->get_param( 'descriptors' ), function ( $d ) {
			return is_array( $d ) && ! empty( $d['uuid'] ) && ! empty( $d['kind'] );
		} ) );
		return array( 'items' => WPD_Importer::manifest( $descs, (array) $req->get_param( 'patterns' ) ) );
	}

	public static function files_check( WP_REST_Request $req ) {
		return array( 'files' => WPD_Importer::files_check( (array) $req->get_param( 'files' ) ) );
	}

	public static function files( WP_REST_Request $req ) {
		$r = WPD_Importer::receive_file( $req->get_param( 'path' ), $req->get_param( 'data' ), $req->get_param( 'sha1' ), (bool) $req->get_param( 'overwrite' ) );
		return is_wp_error( $r ) ? $r : array( 'result' => $r );
	}

	public static function import( WP_REST_Request $req ) {
		$package = $req->get_json_params();
		return WPD_Importer::import( is_array( $package ) ? $package : array(), ! empty( $package['dry_run'] ) );
	}

	/* ---------- local ---------- */

	public static function local_status( WP_REST_Request $req ) {
		return WPD_Pusher::status( (string) $req->get_param( 'env' ) );
	}

	public static function local_push( WP_REST_Request $req ) {
		$uuids = array_map( 'strval', (array) $req->get_param( 'uuids' ) );
		if ( ! $uuids ) {
			return new WP_Error( 'wpd_empty', 'Nothing selected.', array( 'status' => 400 ) );
		}
		return WPD_Pusher::push( (string) $req->get_param( 'env' ), $uuids, (bool) $req->get_param( 'dry_run' ), array_map( 'strval', (array) $req->get_param( 'force' ) ) );
	}

	public static function local_test( WP_REST_Request $req ) {
		return WPD_Pusher::handshake( (string) $req->get_param( 'env' ) );
	}
}
