<?php
/**
 * Turns site-specific values into portable tokens on the sending side, and back on the receiving side.
 *
 *   https://local.test/wp-content/uploads   → {{wpd:uploads}}      (and {{wpd:uploads_esc}} for JSON-escaped "https:\/\/…")
 *   https://local.test                      → {{wpd:home}}         ({{wpd:home_esc}})
 *   a post / attachment ID inside content   → {{wpd:id:<uuid>:<local id>}}
 *
 * IDs are recognised in: wp-image-N classes, id="attachment_N", the ID attributes of known shortcodes
 * (Forminator, LiveCanvas lc_get_post, gallery; extend with the `wpd_id_shortcodes` filter), block comment
 * attributes (id, ref, mediaId, featuredImage) and ID meta keys (`_thumbnail_id`; filter `wpd_id_meta_keys`).
 * Referenced attachments and upload files are collected so they travel with the push, and other referenced
 * posts are collected as dependencies.
 */

defined( 'ABSPATH' ) || exit;

class WPD_Portable {

	/** uuid => descriptor of every post/attachment referenced by ID. */
	public $refs = array();
	/** local attachment ID => true. */
	public $media = array();
	/** relative upload paths referenced by URL that belong to no attachment. */
	public $files = array();
	/** descriptors of posts referenced by slug (e.g. lc_get_post slug="…"). */
	public $slug_refs = array();

	private $url_pairs;
	private $upload_index;
	private $uploads_path;

	public function __construct() {
		$this->url_pairs = array();
		$uploads         = wp_get_upload_dir();
		foreach ( array( 'uploads' => $uploads['baseurl'], 'home' => home_url() ) as $name => $url ) {
			$url = untrailingslashit( $url );
			$alt = 0 === strpos( $url, 'https://' ) ? 'http://' . substr( $url, 8 ) : 'https://' . substr( $url, 7 );
			foreach ( array( $url, $alt ) as $u ) {
				$this->url_pairs[ str_replace( '/', '\/', $u ) ] = '{{wpd:' . $name . '_esc}}';
				$this->url_pairs[ $u ]                           = '{{wpd:' . $name . '}}';
			}
		}
	}

	public static function id_shortcodes() {
		return apply_filters( 'wpd_id_shortcodes', array(
			'forminator_form' => array( 'id' ),
			'forminator_poll' => array( 'id' ),
			'forminator_quiz' => array( 'id' ),
			'lc_get_post'     => array( 'id' ),
			'gallery'         => array( 'ids', 'include', 'exclude' ),
		) );
	}

	public static function id_meta_keys() {
		return apply_filters( 'wpd_id_meta_keys', array( '_thumbnail_id' ) );
	}

	/**
	 * Places where a post stores its own ID inside its meta: post type => meta key => list of key paths.
	 * Forminator, for one, keeps the form's ID in settings.form_id and uses it in field IDs.
	 */
	public static function self_id_paths() {
		$forminator = array( 'forminator_form_meta' => array( array( 'settings', 'form_id' ) ) );
		return apply_filters( 'wpd_self_id_paths', array(
			'forminator_forms'   => $forminator,
			'forminator_polls'   => $forminator,
			'forminator_quizzes' => $forminator,
		) );
	}

	/* ================= sending side ================= */

	/** Tokenise any value (strings inside arrays too). */
	public function tokenise( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = $this->tokenise( $v );
			}
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		$value = strtr( $value, $this->url_pairs );
		$value = $this->tokenise_ids( $value );
		$this->collect_upload_refs( $value );
		return $value;
	}

	/** Token for a referenced post ID, or the ID unchanged if no such post exists. */
	public function id_token( $id ) {
		$id   = (int) $id;
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || in_array( $post->post_type, array( 'revision', 'nav_menu_item' ), true ) ) {
			return (string) $id;
		}
		$desc                       = WPD_Sources::post_descriptor( $post );
		$this->refs[ $desc['uuid'] ] = $desc;
		if ( 'attachment' === $post->post_type ) {
			$this->media[ $id ] = true;
		}
		return '{{wpd:id:' . $desc['uuid'] . ':' . $id . '}}';
	}

	private function tokenise_ids( $s ) {
		$self = $this;

		$s = preg_replace_callback( '/\bwp-image-(\d+)\b/', function ( $m ) use ( $self ) {
			return 'attachment' === get_post_type( (int) $m[1] ) ? 'wp-image-' . $self->id_token( $m[1] ) : $m[0];
		}, $s );

		$s = preg_replace_callback( '/\bid=(["\'])attachment_(\d+)\1/', function ( $m ) use ( $self ) {
			return 'id=' . $m[1] . 'attachment_' . $self->id_token( $m[2] ) . $m[1];
		}, $s );

		$map = self::id_shortcodes();
		if ( $map && false !== strpos( $s, '[' ) ) {
			$tags = implode( '|', array_map( 'preg_quote', array_keys( $map ) ) );
			$s    = preg_replace_callback( '/\[(' . $tags . ')\b([^\]]*)\]/', function ( $m ) use ( $self, $map ) {
				$attrs = $m[2];
				foreach ( $map[ $m[1] ] as $attr ) {
					$attrs = preg_replace_callback( '/\b(' . preg_quote( $attr, '/' ) . ')\s*=\s*(["\']?)([\d,\s]+)\2/', function ( $a ) use ( $self ) {
						$ids = preg_replace_callback( '/\d+/', function ( $n ) use ( $self ) {
							return $self->id_token( $n[0] );
						}, $a[3] );
						return $a[1] . '=' . $a[2] . $ids . $a[2];
					}, $attrs );
				}
				if ( 'lc_get_post' === $m[1] && preg_match( '/\bslug=(["\'])([^"\']+)\1/', $attrs, $slug ) ) {
					$pt = preg_match( '/\bpost_type=(["\'])([^"\']+)\1/', $attrs, $t ) ? $t[2] : 'post';
					$self->slug_refs[ $pt . '/' . $slug[2] ] = array( 'post_type' => $pt, 'slug' => $slug[2] );
				}
				return '[' . $m[1] . $attrs . ']';
			}, $s );
		}

		if ( false !== strpos( $s, '<!-- wp:' ) ) {
			$s = preg_replace_callback( '/<!--\s+wp:[a-z0-9\/-]+\s+(\{.*?\})\s+\/?-->/s', function ( $m ) use ( $self ) {
				$json = preg_replace_callback( '/"(id|ref|mediaId|featuredImage)":(\d+)/', function ( $a ) use ( $self ) {
					return '"' . $a[1] . '":' . $self->id_token( $a[2] );
				}, $m[1] );
				return str_replace( $m[1], $json, $m[0] );
			}, $s );
		}
		return $s;
	}

	/**
	 * Collect upload files referenced by full URL (already tokenised) or by root-relative path
	 * ("/wp-content/uploads/…", which works unchanged on the target, so it is not tokenised).
	 */
	private function collect_upload_refs( $s ) {
		if ( null === $this->uploads_path ) {
			$this->uploads_path = untrailingslashit( (string) wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_PATH ) );
		}
		$rel_path = '' !== $this->uploads_path ? $this->uploads_path : null;
		if ( false === strpos( $s, '{{wpd:uploads' ) && ( ! $rel_path || ( false === strpos( $s, $rel_path ) && false === strpos( $s, str_replace( '/', '\/', $rel_path ) ) ) ) ) {
			return;
		}
		$prefix = '\{\{wpd:uploads(?:_esc)?\}\}';
		if ( $rel_path ) {
			$quoted  = preg_quote( $rel_path, '#' );
			$prefix .= '|(?<![A-Za-z0-9._\-}])' . $quoted . '|(?<![A-Za-z0-9._\-}])' . str_replace( '/', '\\\\/', $quoted );
		}
		if ( ! preg_match_all( '#(?:' . $prefix . ')((?:\\\\?/[A-Za-z0-9._\-%@+~]+)+)#', $s, $m ) ) {
			return;
		}
		$index   = $this->upload_index();
		$basedir = wp_get_upload_dir()['basedir'];
		foreach ( $m[1] as $path ) {
			$rel = ltrim( rawurldecode( str_replace( '\\/', '/', $path ) ), '/' );
			if ( isset( $index[ $rel ] ) ) {
				$this->id_token( $index[ $rel ] );
			} elseif ( is_file( $basedir . '/' . $rel ) && false === strpos( $rel, '..' ) ) {
				$this->files[ $rel ] = true;
			}
		}
	}

	/** relative path of every attachment file (original, scaled, sizes) => attachment ID */
	private function upload_index() {
		if ( null !== $this->upload_index ) {
			return $this->upload_index;
		}
		global $wpdb;
		$this->upload_index = array();
		$rows               = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_wp_attached_file','_wp_attachment_metadata')" );
		foreach ( $rows as $r ) {
			$id = (int) $r->post_id;
			if ( '_wp_attached_file' === $r->meta_key ) {
				$this->upload_index[ $r->meta_value ] = $id;
				continue;
			}
			$meta = maybe_unserialize( $r->meta_value );
			if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
				continue;
			}
			$dir = dirname( $meta['file'] );
			$dir = '.' === $dir ? '' : $dir . '/';
			if ( ! empty( $meta['original_image'] ) ) {
				$this->upload_index[ $dir . $meta['original_image'] ] = $id;
			}
			foreach ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array() as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$this->upload_index[ $dir . $size['file'] ] = $id;
				}
			}
		}
		return $this->upload_index;
	}

	/* ================= receiving side ================= */

	/**
	 * @param mixed $value
	 * @param array $map        uuid => ID on this site
	 * @param array $unresolved collects uuids that could not be mapped (their local ID is kept)
	 */
	public static function resolve( $value, array $map, array &$unresolved ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::resolve( $v, $map, $unresolved );
			}
			return $value;
		}
		if ( ! is_string( $value ) || false === strpos( $value, '{{wpd:' ) ) {
			return $value;
		}
		$uploads = untrailingslashit( wp_get_upload_dir()['baseurl'] );
		$home    = untrailingslashit( home_url() );
		$value   = strtr( $value, array(
			'{{wpd:uploads_esc}}' => str_replace( '/', '\/', $uploads ),
			'{{wpd:uploads}}'     => $uploads,
			'{{wpd:home_esc}}'    => str_replace( '/', '\/', $home ),
			'{{wpd:home}}'        => $home,
		) );
		return preg_replace_callback( '/\{\{wpd:id:([A-Za-z0-9_-]+):(\d+)\}\}/', function ( $m ) use ( $map, &$unresolved ) {
			if ( isset( $map[ $m[1] ] ) ) {
				return (string) $map[ $m[1] ];
			}
			$unresolved[ $m[1] ] = true;
			return $m[2];
		}, $value );
	}

	/** Stable hash of a nested value. */
	public static function hash( $data ) {
		return md5( (string) wp_json_encode( self::ksort_deep( $data ) ) );
	}

	private static function ksort_deep( $v ) {
		if ( ! is_array( $v ) ) {
			return $v;
		}
		ksort( $v );
		foreach ( $v as $k => $x ) {
			$v[ $k ] = self::ksort_deep( $x );
		}
		return $v;
	}
}
