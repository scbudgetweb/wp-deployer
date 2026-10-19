<?php
/**
 * Plugin Name:       WP Deployer
 * Description:       Push content and design changes (pages, builder partials, forms, menus, CSS, theme settings, media) from one WordPress site to another, item by item, without overwriting the receiving site's database.
 * Version:           0.3.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Shaun Corness
 * Text Domain:       wp-deployer
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/scbudgetweb/wp-deployer
 */

defined( 'ABSPATH' ) || exit;

define( 'WPD_VERSION', '0.3.0' );
define( 'WPD_FILE', __FILE__ );
define( 'WPD_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPD_URL', plugin_dir_url( __FILE__ ) );

require_once WPD_DIR . 'includes/class-wpd-settings.php';
require_once WPD_DIR . 'includes/class-wpd-sources.php';
require_once WPD_DIR . 'includes/class-wpd-replace.php';
require_once WPD_DIR . 'includes/class-wpd-portable.php';
require_once WPD_DIR . 'includes/class-wpd-exporter.php';
require_once WPD_DIR . 'includes/class-wpd-state.php';
require_once WPD_DIR . 'includes/class-wpd-history.php';
require_once WPD_DIR . 'includes/class-wpd-importer.php';
require_once WPD_DIR . 'includes/class-wpd-client.php';
require_once WPD_DIR . 'includes/class-wpd-pusher.php';
require_once WPD_DIR . 'includes/class-wpd-rest.php';
require_once WPD_DIR . 'includes/class-wpd-admin.php';
require_once WPD_DIR . 'includes/class-wpd-updater.php';
require_once WPD_DIR . 'includes/class-wpd-setup.php';
require_once WPD_DIR . 'includes/class-wpd-clone.php';
require_once WPD_DIR . 'includes/class-wpd-cache.php';

register_activation_hook( __FILE__, array( 'WPD_History', 'install' ) );

WPD_Sources::watch_registrations();

add_action( 'plugins_loaded', function () {
	if ( get_option( 'wpd_db_version' ) !== WPD_History::DB_VERSION ) {
		WPD_History::install();
	}
	WPD_Rest::init();
	WPD_Updater::init();
	if ( is_admin() ) {
		WPD_Admin::init();
	}
} );
