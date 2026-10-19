<?php
/**
 * Plugin settings: push targets (environments), which content sources are pushed, and the meta / theme-mod
 * patterns. Application passwords are stored encrypted with a key derived from this site's AUTH salt, so
 * changing the salts means re-entering them.
 */

defined( 'ABSPATH' ) || exit;

class WPD_Settings {

	const OPTION = 'wpd_settings';

	/** Meta that is local bookkeeping: never sent, never touched on the receiving site. */
	const INTERNAL_META = array(
		'_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_desired_post_slug', '_wp_trash_meta_*',
		'_encloseme', '_pingme', '_wpd_*', '_lccb_*', '_lc_last_activity_*', '_wp_attached_file', '_wp_attachment_metadata',
		'_wp_attachment_backup_sizes',
	);

	public static function defaults() {
		return array(
			'environments'       => array(),
			'sources'            => array(),   // source key => bool; unset keys fall back to WPD_Sources::default_enabled().
			'protected_meta'     => implode( "\n", array(
				'_yoast_wpseo_*', 'rank_math_*', '_aioseo_*', '_seopress_*', '_genesis_*', 'forminator_custom_sequence_counter',
			) ),
			'theme_mod_patterns' => implode( "\n", array( 'SCSSvar_*', 'picostrap_*', 'headings_font_object', 'body_font_object' ) ),
			'option_patterns'    => 'lc_settings',
			'receive'            => false,
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function update( array $settings ) {
		update_option( self::OPTION, $settings, false );
	}

	public static function receive_enabled() {
		return ! empty( self::get()['receive'] );
	}

	public static function source_enabled( $key ) {
		$sources = self::get()['sources'];
		return array_key_exists( $key, $sources ) ? (bool) $sources[ $key ] : WPD_Sources::default_enabled( $key );
	}

	/** @return string[] */
	public static function lines( $key ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) self::get()[ $key ] ) ) ) );
	}

	public static function protected_meta() {
		return self::lines( 'protected_meta' );
	}

	public static function theme_mod_patterns() {
		return self::lines( 'theme_mod_patterns' );
	}

	/** Options that are never pushed even if a pattern matches: site identity, plugins, users, secrets, runtime data. */
	const NEVER_OPTIONS = array(
		'siteurl', 'home', 'blogname', 'admin_email', 'new_admin_email', 'active_plugins', 'template', 'stylesheet',
		'*_user_roles', 'cron', 'rewrite_rules', 'db_version', 'initial_db_version', 'wpd_*', 'theme_mods_*', 'auth_*',
		'secure_auth_*', 'logged_in_*', 'nonce_*', '_transient_*', '_site_transient_*', '*_key', '*_apikey', '*_api_key',
		'*_secret', '*_token', '*password*', 'recently_activated', 'uninstall_plugins', 'fresh_site',
	);

	public static function option_patterns() {
		return self::lines( 'option_patterns' );
	}

	/** Names of this site's options matching the given patterns, minus NEVER_OPTIONS. */
	public static function matching_options( array $patterns ) {
		global $wpdb;
		if ( ! $patterns ) {
			return array();
		}
		$names = array();
		foreach ( $patterns as $p ) {
			$like  = str_replace( "\x01", '%', $wpdb->esc_like( str_replace( '*', "\x01", $p ) ) );
			$names = array_merge( $names, $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ) );
		}
		$names = array_filter( array_unique( $names ), function ( $n ) use ( $patterns ) {
			return self::matches( $n, $patterns ) && ! self::matches( $n, self::NEVER_OPTIONS );
		} );
		sort( $names );
		return $names;
	}

	/** Glob match (`*` only), case-sensitive. */
	public static function matches( $name, array $patterns ) {
		foreach ( $patterns as $p ) {
			$re = '/^' . str_replace( '\*', '.*', preg_quote( $p, '/' ) ) . '$/';
			if ( preg_match( $re, (string) $name ) ) {
				return true;
			}
		}
		return false;
	}

	public static function environment( $id ) {
		foreach ( self::get()['environments'] as $env ) {
			if ( $env['id'] === $id ) {
				$env['password'] = self::decrypt( isset( $env['password_enc'] ) ? $env['password_enc'] : '' );
				return $env;
			}
		}
		return null;
	}

	/* ---------- encryption ---------- */

	private static function key() {
		return sodium_crypto_generichash( wp_salt( 'auth' ) . '|wp-deployer', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public static function encrypt( $plain ) {
		if ( '' === $plain ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
	}

	public static function decrypt( $enc ) {
		$raw = base64_decode( (string) $enc, true );
		if ( ! $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::key()
		);
		return false === $plain ? '' : $plain;
	}
}
