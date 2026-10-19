<?php
/**
 * Clears caches on this site after it receives a push or a setup, so visitors see the change straight away.
 * Covers the object cache plus the common page-cache and optimisation plugins and hosts (the list follows
 * Duplicator's). Extend with the `wpd_purge_caches` action.
 */

defined( 'ABSPATH' ) || exit;

class WPD_Cache {

	/** @return string[] names of the caches that were cleared */
	public static function purge() {
		$done = array();
		$try  = function ( $name, $when, $run ) use ( &$done ) {
			try {
				if ( $when() ) {
					$run();
					$done[] = $name;
				}
			} catch ( Throwable $e ) { // a broken cache plugin must not fail the push
				$done[] = $name . ' (failed: ' . $e->getMessage() . ')';
			}
		};

		$try( 'Object cache', '__return_true', function () {
			wp_cache_flush();
		} );
		$try( 'LiteSpeed Cache', function () {
			return has_action( 'litespeed_purge_all' );
		}, function () {
			do_action( 'litespeed_purge_all' );
		} );
		$try( 'WP Rocket', function () {
			return function_exists( 'rocket_clean_domain' );
		}, function () {
			rocket_clean_domain();
			if ( function_exists( 'rocket_clean_minify' ) ) {
				rocket_clean_minify();
			}
		} );
		$try( 'W3 Total Cache', function () {
			return function_exists( 'w3tc_flush_all' );
		}, function () {
			w3tc_flush_all();
		} );
		$try( 'WP Super Cache', function () {
			return function_exists( 'wp_cache_clear_cache' );
		}, function () {
			wp_cache_clear_cache();
		} );
		$try( 'WP Fastest Cache', function () {
			return function_exists( 'wpfc_clear_all_cache' );
		}, function () {
			wpfc_clear_all_cache( true );
		} );
		$try( 'SiteGround Optimizer', function () {
			return function_exists( 'sg_cachepress_purge_cache' );
		}, function () {
			sg_cachepress_purge_cache();
		} );
		$try( 'Breeze', function () {
			return class_exists( 'Breeze_PurgeCache' );
		}, function () {
			do_action( 'breeze_clear_all_cache' );
		} );
		$try( 'Autoptimize', function () {
			return class_exists( 'autoptimizeCache' );
		}, function () {
			autoptimizeCache::clearall();
		} );
		$try( 'WP-Optimize', function () {
			return function_exists( 'WP_Optimize' ) && method_exists( WP_Optimize(), 'get_page_cache' );
		}, function () {
			WP_Optimize()->get_page_cache()->purge();
		} );
		$try( 'Hummingbird', function () {
			return has_action( 'wphb_clear_page_cache' );
		}, function () {
			do_action( 'wphb_clear_page_cache' );
		} );
		$try( 'Nginx Helper', function () {
			return has_action( 'rt_nginx_helper_purge_all' );
		}, function () {
			do_action( 'rt_nginx_helper_purge_all' );
		} );
		$try( 'Cache Enabler', function () {
			return has_action( 'cache_enabler_clear_complete_cache' );
		}, function () {
			do_action( 'cache_enabler_clear_complete_cache' );
		} );
		$try( 'Cachify', function () {
			return function_exists( 'cachify_flush_cache' );
		}, function () {
			cachify_flush_cache();
		} );
		$try( 'Comet Cache', function () {
			return class_exists( 'comet_cache' );
		}, function () {
			comet_cache::clear();
		} );
		$try( 'Cloudflare (WP Cloudflare Super Page Cache)', function () {
			return class_exists( 'SW_CLOUDFLARE_PAGECACHE' );
		}, function () {
			do_action( 'swcfpc_purge_everything' );
		} );
		$try( 'WP Engine', function () {
			return class_exists( 'WpeCommon' );
		}, function () {
			foreach ( array( 'purge_memcached', 'purge_varnish_cache', 'clear_maxcdn_cache' ) as $m ) {
				if ( method_exists( 'WpeCommon', $m ) ) {
					call_user_func( array( 'WpeCommon', $m ) );
				}
			}
		} );
		$try( 'GoDaddy', function () {
			return class_exists( '\WPaaS\Plugin' );
		}, function () {
			do_action( 'wpaas_ban_all' );
		} );
		$try( 'Elementor CSS', function () {
			return class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager );
		}, function () {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		} );
		do_action( 'wpd_purge_caches' );
		return $done;
	}
}
