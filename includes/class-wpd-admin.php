<?php
/**
 * Admin screens: Push (choose a target, see what differs, preview, push), History (pushes this site received,
 * with rollback) and Settings (targets, content sources, protected meta, receiving).
 */

defined( 'ABSPATH' ) || exit;

class WPD_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wpd_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_wpd_rollback', array( __CLASS__, 'rollback' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		add_menu_page( 'WP Deployer', 'WP Deployer', 'manage_options', 'wpd-push', array( __CLASS__, 'page_push' ), 'dashicons-upload', 80 );
		add_submenu_page( 'wpd-push', 'Push changes', 'Push', 'manage_options', 'wpd-push', array( __CLASS__, 'page_push' ) );
		add_submenu_page( 'wpd-push', 'Received pushes', 'History', 'manage_options', 'wpd-history', array( __CLASS__, 'page_history' ) );
		add_submenu_page( 'wpd-push', 'WP Deployer settings', 'Settings', 'manage_options', 'wpd-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'wpd-' ) ) {
			return;
		}
		wp_enqueue_style( 'wpd-admin', WPD_URL . 'assets/admin.css', array(), WPD_VERSION );
		if ( false !== strpos( $hook, 'wpd-push' ) ) {
			wp_enqueue_script( 'wpd-admin', WPD_URL . 'assets/admin.js', array(), WPD_VERSION, true );
			$envs = array();
			foreach ( WPD_Settings::get()['environments'] as $e ) {
				$envs[] = array( 'id' => $e['id'], 'name' => $e['name'], 'url' => $e['url'] );
			}
			wp_localize_script( 'wpd-admin', 'WPD', array(
				'rest'     => esc_url_raw( rest_url( WPD_Rest::NS ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'envs'     => $envs,
				'settings' => admin_url( 'admin.php?page=wpd-settings' ),
			) );
		}
	}

	/* ---------- Push ---------- */

	public static function page_push() {
		$envs = WPD_Settings::get()['environments'];
		echo '<div class="wrap wpd"><h1>Push changes</h1>';
		if ( ! $envs ) {
			echo '<p>No targets yet. <a href="' . esc_url( admin_url( 'admin.php?page=wpd-settings' ) ) . '">Add staging or production in Settings</a>.</p></div>';
			return;
		}
		?>
		<p class="wpd-intro">Pick a target, tick what to send, preview, then push. Images and anything the selected items need (forms, sections, parent pages) go with them. Nothing else on the target is touched: form entries, users, comments, SEO data and plugin settings stay as they are.</p>
		<div class="wpd-bar">
			<label>Target <select id="wpd-env">
				<?php foreach ( $envs as $e ) : ?>
					<option value="<?php echo esc_attr( $e['id'] ); ?>"><?php echo esc_html( $e['name'] . ' — ' . $e['url'] ); ?></option>
				<?php endforeach; ?>
			</select></label>
			<button type="button" class="button" id="wpd-load">Compare</button>
			<span class="wpd-spacer"></span>
			<button type="button" class="button" id="wpd-select-changed" disabled>Select all changes</button>
			<button type="button" class="button" id="wpd-preview" disabled>Preview push</button>
			<button type="button" class="button button-primary" id="wpd-push" disabled>Push</button>
		</div>
		<div id="wpd-message"></div>
		<div id="wpd-items"></div>
		<div id="wpd-result"></div>
		</div>
		<?php
	}

	/* ---------- History ---------- */

	public static function page_history() {
		echo '<div class="wrap wpd"><h1>Received pushes</h1>';
		if ( isset( $_GET['wpd_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( wp_unslash( $_GET['wpd_msg'] ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		}
		if ( ! WPD_Settings::receive_enabled() ) {
			echo '<p class="description">This site does not currently accept pushes (Settings › Receive pushes). Past pushes are still listed.</p>';
		}
		$pushes = WPD_History::pushes();
		if ( ! $pushes ) {
			echo '<p>No pushes received yet.</p></div>';
			return;
		}
		echo '<table class="widefat striped wpd-history"><thead><tr><th>When (UTC)</th><th>From</th><th>By</th><th>Status</th><th>Changes</th><th></th></tr></thead><tbody>';
		foreach ( $pushes as $p ) {
			$s      = is_array( $p['summary'] ) ? $p['summary'] : array();
			$counts = isset( $s['counts'] ) ? $s['counts'] : array();
			$parts  = array();
			foreach ( array( 'create' => 'created', 'update' => 'updated', 'unchanged' => 'unchanged', 'skip' => 'skipped' ) as $k => $label ) {
				if ( ! empty( $counts[ $k ] ) ) {
					$parts[] = $counts[ $k ] . ' ' . $label;
				}
			}
			echo '<tr><td>' . esc_html( $p['created_at'] ) . '</td><td>' . esc_html( $p['source'] ) . '</td><td>' . esc_html( $p['user_login'] ) . '</td>';
			echo '<td><span class="wpd-badge wpd-' . esc_attr( $p['status'] ) . '">' . esc_html( str_replace( '_', ' ', $p['status'] ) ) . '</span></td><td>';
			echo esc_html( $parts ? implode( ', ', $parts ) : ( isset( $s['error'] ) ? $s['error'] : '' ) );
			if ( ! empty( $s['items'] ) ) {
				echo '<details><summary>Items</summary><ul>';
				foreach ( $s['items'] as $i ) {
					echo '<li><strong>' . esc_html( $i['action'] ) . '</strong> ' . esc_html( $i['label'] ) . ' <span class="description">' . esc_html( $i['kind'] . ( $i['note'] ? ' — ' . $i['note'] : '' ) ) . '</span></li>';
				}
				echo '</ul></details>';
			}
			echo '</td><td>';
			if ( 'done' === $p['status'] ) {
				$overlap = WPD_History::later_overlaps( $p['id'] );
				$warn    = $overlap ? 'Later pushes changed some of the same items (' . implode( ', ', $overlap ) . '). Rolling this back restores them to how they were before this push. Continue?' : 'Restore everything this push changed, and delete what it created?';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( wp_json_encode( $warn ) ) . ')">';
				echo '<input type="hidden" name="action" value="wpd_rollback"><input type="hidden" name="push_id" value="' . esc_attr( $p['id'] ) . '">';
				wp_nonce_field( 'wpd_rollback_' . $p['id'] );
				echo '<button class="button">Roll back</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function rollback() {
		$id = isset( $_POST['push_id'] ) ? (int) $_POST['push_id'] : 0;
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'wpd_rollback_' . $id ) ) {
			wp_die( 'Not allowed.' );
		}
		$r   = WPD_History::rollback( $id );
		$msg = is_wp_error( $r ) ? $r->get_error_message() : 'Rolled back push #' . $id . ' (' . count( $r ) . ' items restored).';
		wp_safe_redirect( add_query_arg( 'wpd_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=wpd-history' ) ) );
		exit;
	}

	/* ---------- Settings ---------- */

	public static function page_settings() {
		$s = WPD_Settings::get();
		echo '<div class="wrap wpd"><h1>WP Deployer settings</h1>';
		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wpd_save_settings">
			<?php wp_nonce_field( 'wpd_save_settings' ); ?>

			<h2>Push targets</h2>
			<p class="description">On each target: install and activate WP Deployer, tick <em>Receive pushes</em> in its settings, then create an application password under <em>Users › Profile › Application Passwords</em> for an administrator, and paste it here. Targets must use HTTPS.</p>
			<table class="widefat wpd-envs">
				<thead><tr><th>Name</th><th>Site URL</th><th>Admin username</th><th>Application password</th><th>Verify SSL</th><th>Remove</th></tr></thead>
				<tbody>
				<?php
				$rows   = $s['environments'];
				$rows[] = array( 'id' => '', 'name' => '', 'url' => '', 'user' => '', 'password_enc' => '', 'verify_ssl' => true );
				foreach ( $rows as $n => $e ) :
					$has = ! empty( $e['password_enc'] );
					?>
					<tr>
						<td><input type="hidden" name="envs[<?php echo (int) $n; ?>][id]" value="<?php echo esc_attr( $e['id'] ); ?>">
							<input type="text" name="envs[<?php echo (int) $n; ?>][name]" value="<?php echo esc_attr( $e['name'] ); ?>" placeholder="<?php echo $e['id'] ? '' : 'Add: e.g. Staging'; ?>"></td>
						<td><input type="url" class="regular-text" name="envs[<?php echo (int) $n; ?>][url]" value="<?php echo esc_attr( $e['url'] ); ?>" placeholder="https://"></td>
						<td><input type="text" name="envs[<?php echo (int) $n; ?>][user]" value="<?php echo esc_attr( $e['user'] ); ?>" autocomplete="off"></td>
						<td><input type="password" name="envs[<?php echo (int) $n; ?>][password]" value="" autocomplete="new-password" placeholder="<?php echo $has ? 'Saved — leave blank to keep' : 'xxxx xxxx xxxx xxxx xxxx xxxx'; ?>"></td>
						<td><input type="checkbox" name="envs[<?php echo (int) $n; ?>][verify_ssl]" value="1" <?php checked( ! isset( $e['verify_ssl'] ) || $e['verify_ssl'] ); ?>></td>
						<td><?php if ( $e['id'] ) : ?><input type="checkbox" name="envs[<?php echo (int) $n; ?>][remove]" value="1"><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2>What can be pushed</h2>
			<p class="description">Detected from the plugins and theme on this site. Only ticked sources appear on the Push screen. Untick content that is written on the live site (for example blog posts) so it is never pushed. Content is never deleted on a target.</p>
			<?php
			$groups = array();
			foreach ( WPD_Sources::all() as $src ) {
				$groups[ $src['group'] ][] = $src;
			}
			uksort( $groups, function ( $a, $b ) {
				$rank = function ( $g ) {
					return 'WordPress' === $g ? 0 : ( 'Site design & settings' === $g ? 2 : 1 );
				};
				return $rank( $a ) - $rank( $b ) ?: strcasecmp( $a, $b );
			} );
			echo '<div class="wpd-sources">';
			foreach ( $groups as $group => $sources ) {
				echo '<fieldset><legend>' . esc_html( $group ) . '</legend>';
				foreach ( $sources as $src ) {
					$on = WPD_Settings::source_enabled( $src['key'] );
					echo '<label class="' . ( $src['available'] ? '' : 'wpd-unavailable' ) . '"><input type="hidden" name="sources[' . esc_attr( $src['key'] ) . ']" value="0">';
					echo '<input type="checkbox" name="sources[' . esc_attr( $src['key'] ) . ']" value="1" ' . checked( $on, true, false ) . disabled( ! $src['available'], true, false ) . '> ';
					echo '<strong>' . esc_html( $src['label'] ) . '</strong> <span class="count">(' . (int) $src['count'] . ')</span>';
					echo '<br><span class="description">' . esc_html( $src['description'] ) . '</span></label>';
				}
				echo '</fieldset>';
			}
			echo '</div>';
			?>

			<h2>Protected data</h2>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="wpd-protected">Post meta never pushed or overwritten</label></th>
					<td><textarea id="wpd-protected" name="protected_meta" rows="7" class="large-text code"><?php echo esc_textarea( $s['protected_meta'] ); ?></textarea>
						<p class="description">One key per line, <code>*</code> as wildcard. Applies on both sides: this site does not send these, and as a target it keeps its own values. SEO plugin fields are here by default, so titles and descriptions written on the live site survive a push.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="wpd-mods">Theme settings that are pushed</label></th>
					<td><textarea id="wpd-mods" name="theme_mod_patterns" rows="5" class="large-text code"><?php echo esc_textarea( $s['theme_mod_patterns'] ); ?></textarea>
						<p class="description">Customizer setting names (theme mods), one per line, <code>*</code> as wildcard. Menu locations are pushed with menus.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="wpd-options">Plugin settings that are pushed</label></th>
					<td><textarea id="wpd-options" name="option_patterns" rows="4" class="large-text code"><?php echo esc_textarea( $s['option_patterns'] ); ?></textarea>
						<p class="description">Option names, one per line, <code>*</code> as wildcard. Only list settings that belong to the build, such as <code>lc_settings</code> (LiveCanvas header, footer and dynamic templates switches). Plugin activation, site URL and name, users and anything that looks like a key, token, secret or password are never pushed.
						<?php
						$names = WPD_Settings::matching_options( WPD_Settings::option_patterns() );
						echo $names ? '<br>Currently matches: <code>' . implode( '</code>, <code>', array_map( 'esc_html', $names ) ) . '</code>' : '';
						?>
						</p></td>
				</tr>
			</table>

			<h2>Receiving</h2>
			<label><input type="checkbox" name="receive" value="1" <?php checked( $s['receive'] ); ?>> <strong>Receive pushes</strong> — let administrators of this site push to it from another site, using an application password.</label>
			<p class="description">Tick this on staging and production. Leave it off on your local site.</p>

			<h2>Plugin updates</h2>
			<?php
			$release = WPD_Updater::latest();
			if ( WPD_Updater::is_checkout() ) {
				echo '<p class="description">This copy is a git checkout, so WordPress won\'t offer updates for it. Use git here.</p>';
			}
			if ( is_wp_error( $release ) ) {
				echo '<p class="description"><span class="wpd-badge wpd-failed">Can\'t check</span> ' . esc_html( $release->get_error_message() ) . '</p>';
			} else {
				$newer = version_compare( $release['version'], WPD_VERSION, '>' );
				echo '<p>Installed: <strong>' . esc_html( WPD_VERSION ) . '</strong> · Latest on GitHub: <a href="' . esc_url( $release['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $release['tag'] ) . '</a> ';
				echo $newer ? '<span class="wpd-badge wpd-new">Update available</span> <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Plugins</a>' : '<span class="wpd-badge wpd-done">Up to date</span>';
				echo '</p>';
			}
			?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="wpd-gh-token">GitHub token</label></th>
					<td>
						<?php if ( defined( 'WPD_GITHUB_TOKEN' ) && WPD_GITHUB_TOKEN ) : ?>
							<p>Set in <code>wp-config.php</code> (<code>WPD_GITHUB_TOKEN</code>).</p>
						<?php else : ?>
							<input type="password" id="wpd-gh-token" name="github_token" class="regular-text" autocomplete="new-password" placeholder="<?php echo empty( $s['github_token_enc'] ) ? 'github_pat_…' : 'Saved — leave blank to keep'; ?>">
							<?php if ( ! empty( $s['github_token_enc'] ) ) : ?><label><input type="checkbox" name="github_token_remove" value="1"> Remove</label><?php endif; ?>
						<?php endif; ?>
						<p class="description">Updates come from the latest release of <code><?php echo esc_html( WPD_Updater::REPO ); ?></code>, which is private, so each site needs a fine-grained token limited to that repository with <em>Contents: Read-only</em>. Putting <code>define( 'WPD_GITHUB_TOKEN', '…' );</code> in wp-config.php keeps it out of the database; otherwise save it here (stored encrypted).</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Save settings' ); ?>
		</form>
		</div>
		<?php
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'wpd_save_settings' ) ) {
			wp_die( 'Not allowed.' );
		}
		$s   = WPD_Settings::get();
		$old = array();
		foreach ( $s['environments'] as $e ) {
			$old[ $e['id'] ] = $e;
		}
		$envs = array();
		foreach ( isset( $_POST['envs'] ) ? (array) wp_unslash( $_POST['envs'] ) : array() as $row ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$id   = sanitize_key( isset( $row['id'] ) ? $row['id'] : '' );
			$name = sanitize_text_field( isset( $row['name'] ) ? $row['name'] : '' );
			$url  = esc_url_raw( untrailingslashit( trim( isset( $row['url'] ) ? $row['url'] : '' ) ) );
			if ( ! empty( $row['remove'] ) || ( '' === $name && '' === $url ) ) {
				continue;
			}
			$pass   = isset( $row['password'] ) ? trim( $row['password'] ) : '';
			$envs[] = array(
				'id'           => $id ? $id : substr( md5( wp_generate_uuid4() ), 0, 10 ),
				'name'         => '' !== $name ? $name : wp_parse_url( $url, PHP_URL_HOST ),
				'url'          => $url,
				'user'         => sanitize_user( isset( $row['user'] ) ? $row['user'] : '' ),
				'password_enc' => '' !== $pass ? WPD_Settings::encrypt( $pass ) : ( isset( $old[ $id ]['password_enc'] ) ? $old[ $id ]['password_enc'] : '' ),
				'verify_ssl'   => ! empty( $row['verify_ssl'] ),
			);
		}
		$sources = array();
		foreach ( isset( $_POST['sources'] ) ? (array) wp_unslash( $_POST['sources'] ) : array() as $k => $v ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$sources[ sanitize_text_field( $k ) ] = '1' === $v;
		}
		$s['environments']       = $envs;
		$s['sources']            = $sources;
		$s['protected_meta']     = sanitize_textarea_field( wp_unslash( isset( $_POST['protected_meta'] ) ? $_POST['protected_meta'] : '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$s['theme_mod_patterns'] = sanitize_textarea_field( wp_unslash( isset( $_POST['theme_mod_patterns'] ) ? $_POST['theme_mod_patterns'] : '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$s['option_patterns']    = sanitize_textarea_field( wp_unslash( isset( $_POST['option_patterns'] ) ? $_POST['option_patterns'] : '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$s['receive']            = ! empty( $_POST['receive'] );
		$token                   = isset( $_POST['github_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['github_token'] ) ) ) : '';
		if ( ! empty( $_POST['github_token_remove'] ) ) {
			$s['github_token_enc'] = '';
		} elseif ( '' !== $token ) {
			$s['github_token_enc'] = WPD_Settings::encrypt( $token );
		}
		delete_site_transient( WPD_Updater::TRANSIENT );
		WPD_Settings::update( $s );
		wp_safe_redirect( admin_url( 'admin.php?page=wpd-settings&updated=1' ) );
		exit;
	}
}
