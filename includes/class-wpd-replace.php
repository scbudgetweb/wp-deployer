<?php
/**
 * Search and replace for site addresses and paths, safe for serialized, JSON-escaped and URL-encoded data.
 *
 * A URL rule matches the address in every form it is stored in:
 *   https://site.test/x   http://site.test/x   //site.test/x   (also with or without www.)
 *   https:\/\/site.test\/x                       (JSON-escaped)
 *   https%3A%2F%2Fsite.test%2Fx                  (URL-encoded)
 * and only up to a boundary, so site.test never matches inside site.testing.com or site.test2.
 * A path rule matches a server path (plain or JSON-escaped). A string rule replaces literally (emails).
 *
 * Serialized values are never unserialized: map() walks the serialized text with its own (valid) lengths,
 * applies the change to each string inside, and writes the new lengths, so stored objects survive untouched.
 */

defined( 'ABSPATH' ) || exit;

class WPD_Replace {

	/** @var array[] each: type, regex[], forms (form => replacement) */
	private $rules = array();
	private $needles = array();
	private $base64 = false;

	/** The forms an address can be written in, for a real URL (used as replacements). */
	public static function url_forms( $url ) {
		$url  = untrailingslashit( $url );
		$pr   = preg_replace( '#^https?:#i', '', $url );
		$json = function ( $s ) {
			return str_replace( '/', '\/', $s );
		};
		return array(
			'plain'   => $url,
			'json'    => $json( $url ),
			'enc'     => rawurlencode( $url ),
			'pr'      => $pr,
			'pr_json' => $json( $pr ),
			'pr_enc'  => rawurlencode( $pr ),
		);
	}

	/** Token forms for a named address, e.g. {{wpd:home}} / {{wpd:home_esc}} / {{wpd:home_pr}}… */
	public static function token_forms( $name ) {
		return array(
			'plain'   => '{{wpd:' . $name . '}}',
			'json'    => '{{wpd:' . $name . '_esc}}',
			'enc'     => '{{wpd:' . $name . '_enc}}',
			'pr'      => '{{wpd:' . $name . '_pr}}',
			'pr_json' => '{{wpd:' . $name . '_pr_esc}}',
			'pr_enc'  => '{{wpd:' . $name . '_pr_enc}}',
		);
	}

	public function enable_base64() {
		$this->base64 = true;
		return $this;
	}

	/**
	 * @param string $old_url  address to find (scheme and www. are ignored when matching)
	 * @param array  $forms    replacement for each form (see url_forms / token_forms)
	 */
	public function add_url( $old_url, array $forms ) {
		$p = wp_parse_url( untrailingslashit( $old_url ) );
		if ( empty( $p['host'] ) ) {
			return $this;
		}
		$host     = preg_replace( '/^www\./i', '', strtolower( $p['host'] ) ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		$path     = isset( $p['path'] ) ? rtrim( $p['path'], '/' ) : '';
		$hostpath = $host . $path;
		$q        = preg_quote( $hostpath, '#' );
		$qjson    = str_replace( '/', '\\\\\/', $q );
		$qenc     = preg_quote( rawurlencode( $hostpath ), '#' );
		$this->rules[]   = array(
			'type'  => 'url',
			'regex' => array(
				// [form for scheme-ful match, form for protocol-relative match, pattern]
				array( 'json', 'pr_json', '#(https?:)?\\\\/\\\\/(?:www\.)?' . $qjson . '(?=\\\\/|[?\#"\'\s<>,;&|)\]}\\\\]|\.(?![A-Za-z0-9-])|$)#i' ),
				array( 'enc', 'pr_enc', '#(https?%3A)?%2F%2F(?:www\.)?' . $qenc . '(?=%2F|%3F|%23|%22|[&"\'\s<>,;|)\]}]|$)#i' ),
				array( 'plain', 'pr', '#(https?:)?//(?:www\.)?' . $q . '(?=[/?\#"\'\s<>,;&|)\]}\\\\]|\.(?![A-Za-z0-9-])|$)#i' ),
			),
			'forms' => $forms,
		);
		$this->needles[] = $host;
		return $this;
	}

	public function add_path( $old_path, array $forms ) {
		$old_path = untrailingslashit( wp_normalize_path( $old_path ) );
		if ( strlen( $old_path ) < 4 ) {
			return $this; // never replace "/" or similar
		}
		$q             = preg_quote( $old_path, '#' );
		$this->rules[] = array(
			'type'  => 'path',
			'regex' => array(
				array( 'json', 'json', '#' . str_replace( '/', '\\\\\/', $q ) . '(?=\\\\/|["\'\s,;]|$)#' ),
				array( 'plain', 'plain', '#' . $q . '(?=[/\\\\"\'\s,;:]|$)#' ),
			),
			'forms' => $forms,
		);
		$this->needles[] = $old_path;
		$this->needles[] = str_replace( '/', '\/', $old_path );
		return $this;
	}

	public function add_string( $old, $new ) {
		$this->rules[]   = array( 'type' => 'str', 'old' => $old, 'new' => $new );
		$this->needles[] = $old;
		return $this;
	}

	/** Replace in a plain string (not serialized-aware: use replace() for stored values). */
	public function replace_plain( $s ) {
		if ( ! is_string( $s ) || '' === $s || ! $this->has_needle( $s ) ) {
			return $s;
		}
		foreach ( $this->rules as $rule ) {
			if ( 'str' === $rule['type'] ) {
				$s = str_replace( $rule['old'], $rule['new'], $s );
				continue;
			}
			foreach ( $rule['regex'] as $r ) {
				list( $full, $relative, $pattern ) = $r;
				$forms = $rule['forms'];
				$s     = preg_replace_callback( $pattern, function ( $m ) use ( $forms, $full, $relative ) {
					return ! empty( $m[1] ) ? $forms[ $full ] : $forms[ $relative ];
				}, $s );
			}
		}
		return $s;
	}

	private function has_needle( $s ) {
		foreach ( $this->needles as $n ) {
			if ( false !== stripos( $s, $n ) || false !== stripos( $s, rawurlencode( $n ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Replace in a stored value: serialized-aware, and (if enabled) inside base64. */
	public function replace( $s ) {
		if ( ! is_string( $s ) || '' === $s ) {
			return $s;
		}
		$self = $this;
		return self::map( $s, function ( $leaf ) use ( $self ) {
			$new = $self->replace_plain( $leaf );
			if ( $new === $leaf && $self->base64 ) {
				$new = $self->replace_base64( $leaf );
			}
			return $new;
		} );
	}

	private function replace_base64( $s ) {
		if ( strlen( $s ) < 16 || strlen( $s ) % 4 || ! preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $s ) ) {
			return $s;
		}
		$decoded = base64_decode( $s, true );
		if ( false === $decoded || ! $this->has_needle( $decoded ) ) {
			return $s;
		}
		$new = $this->replace( $decoded );
		return $new === $decoded ? $s : base64_encode( $new );
	}

	/* ===================================================================== serialized walker */

	/**
	 * Apply $fn to every string inside a value. If $s is serialized, the strings inside it (at any depth,
	 * including serialized strings inside serialized strings) are changed and their lengths rewritten;
	 * otherwise $fn is applied to $s itself.
	 */
	public static function map( $s, callable $fn ) {
		if ( ! is_string( $s ) || ! is_serialized( $s, false ) ) {
			return $fn( $s );
		}
		try {
			$i   = 0;
			$out = self::walk( $s, $i, $fn );
			if ( $i !== strlen( $s ) ) {
				throw new Exception( 'trailing data' );
			}
			return $out;
		} catch ( Exception $e ) {
			return $fn( $s ); // not valid serialized data after all
		}
	}

	private static function read_until( $s, &$i, $char ) {
		$end = strpos( $s, $char, $i );
		if ( false === $end ) {
			throw new Exception( 'unterminated' );
		}
		$out = substr( $s, $i, $end - $i );
		$i   = $end + 1;
		return $out;
	}

	private static function expect( $s, &$i, $chars ) {
		if ( substr( $s, $i, strlen( $chars ) ) !== $chars ) {
			throw new Exception( 'expected ' . $chars );
		}
		$i += strlen( $chars );
	}

	private static function walk( $s, &$i, callable $fn ) {
		if ( ! isset( $s[ $i ] ) ) {
			throw new Exception( 'eof' );
		}
		$t = $s[ $i ];
		switch ( $t ) {
			case 'N':
				self::expect( $s, $i, 'N;' );
				return 'N;';
			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$start = $i;
				$i    += 2;
				self::read_until( $s, $i, ';' );
				return substr( $s, $start, $i - $start );
			case 's':
				$i  += 2;
				$len = (int) self::read_until( $s, $i, ':' );
				self::expect( $s, $i, '"' );
				$val = substr( $s, $i, $len );
				if ( strlen( $val ) !== $len ) {
					throw new Exception( 'short string' );
				}
				$i += $len;
				self::expect( $s, $i, '";' );
				$new = self::map( $val, $fn );
				return 's:' . strlen( $new ) . ':"' . $new . '";';
			case 'a':
				$i += 2;
				$n  = (int) self::read_until( $s, $i, ':' );
				self::expect( $s, $i, '{' );
				$out = 'a:' . $n . ':{';
				for ( $k = 0; $k < 2 * $n; $k++ ) {
					$out .= self::walk( $s, $i, $fn );
				}
				self::expect( $s, $i, '}' );
				return $out . '}';
			case 'O':
				$start = $i;
				$i    += 2;
				$clen  = (int) self::read_until( $s, $i, ':' );
				$i    += $clen + 3; // "Class":
				$n     = (int) self::read_until( $s, $i, ':' );
				self::expect( $s, $i, '{' );
				$out = substr( $s, $start, $i - $start );
				for ( $k = 0; $k < 2 * $n; $k++ ) {
					$out .= self::walk( $s, $i, $fn );
				}
				self::expect( $s, $i, '}' );
				return $out . '}';
			case 'C': // custom-serialized object: opaque, copied as is
				$start = $i;
				$i    += 2;
				$clen  = (int) self::read_until( $s, $i, ':' );
				$i    += $clen + 3;
				$dlen  = (int) self::read_until( $s, $i, ':' );
				self::expect( $s, $i, '{' );
				$i += $dlen;
				self::expect( $s, $i, '}' );
				return substr( $s, $start, $i - $start );
			case 'E': // enum
				$start = $i;
				$i    += 2;
				$len   = (int) self::read_until( $s, $i, ':' );
				$i    += $len + 2;
				self::expect( $s, $i, ';' );
				return substr( $s, $start, $i - $start );
		}
		throw new Exception( 'unknown type ' . $t );
	}
}
