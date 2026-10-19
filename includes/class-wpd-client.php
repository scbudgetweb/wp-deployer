<?php
/**
 * HTTP client for a push target. Authenticates with a WordPress application password, sent both as the
 * standard Authorization header and as X-WPD-Auth, because some hosts (Apache + FastCGI) strip Authorization
 * before PHP sees it; the receiving plugin accepts either.
 */

defined( 'ABSPATH' ) || exit;

class WPD_Client {

	private $env;

	public function __construct( array $env ) {
		$this->env = $env;
	}

	public static function for_env( $env_id ) {
		$env = WPD_Settings::environment( $env_id );
		if ( ! $env ) {
			return new WP_Error( 'wpd_env', 'Unknown environment.' );
		}
		if ( '' === $env['url'] || '' === $env['user'] || '' === $env['password'] ) {
			return new WP_Error( 'wpd_env', $env['name'] . ' is missing its URL, username or application password (Settings).' );
		}
		return new self( $env );
	}

	public function name() {
		return $this->env['name'];
	}

	public function url( $path = '' ) {
		return untrailingslashit( $this->env['url'] ) . $path;
	}

	/** @return array|WP_Error decoded JSON body */
	public function request( $method, $route, $body = null, $timeout = 120 ) {
		$auth = 'Basic ' . base64_encode( $this->env['user'] . ':' . preg_replace( '/\s+/', '', $this->env['password'] ) );
		$args = array(
			'method'    => $method,
			'timeout'   => $timeout,
			'sslverify' => ! isset( $this->env['verify_ssl'] ) || (bool) $this->env['verify_ssl'],
			'headers'   => array(
				'Authorization' => $auth,
				'X-WPD-Auth'    => $auth,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$res = wp_remote_request( $this->url( '/?rest_route=/wpd/v1' . $route ), $args );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'wpd_http', $this->name() . ': ' . $res->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$raw  = wp_remote_retrieve_body( $res );
		$json = json_decode( $raw, true );
		if ( $code >= 200 && $code < 300 && is_array( $json ) ) {
			return $json;
		}
		$msg = is_array( $json ) && isset( $json['message'] ) ? wp_strip_all_tags( $json['message'] ) : 'HTTP ' . $code . ( '' !== $raw ? ': ' . wp_strip_all_tags( substr( $raw, 0, 300 ) ) : '' );
		if ( 401 === $code || 403 === $code ) {
			$msg .= ' — check the username and application password, and that "Receive pushes" is ticked in WP Deployer settings on ' . $this->name() . '.';
		} elseif ( 404 === $code ) {
			$msg .= ' — is WP Deployer installed and active on ' . $this->name() . '?';
		} elseif ( 413 === $code ) {
			$msg .= ' — the request is larger than the server accepts (raise post_max_size / upload_max_filesize there).';
		}
		return new WP_Error( 'wpd_remote', $this->name() . ': ' . $msg );
	}
}
