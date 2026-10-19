/* WP Deployer: Push screen. */
( function () {
	const $ = ( id ) => document.getElementById( id );
	const env = $( 'wpd-env' );
	if ( ! env ) {
		return;
	}
	const msg = $( 'wpd-message' );
	const list = $( 'wpd-items' );
	const out = $( 'wpd-result' );
	const btn = { load: $( 'wpd-load' ), changed: $( 'wpd-select-changed' ), preview: $( 'wpd-preview' ), push: $( 'wpd-push' ) };
	let items = [];
	let target = null;
	let armTimer = null;

	const esc = ( s ) => String( s ?? '' ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );

	async function api( path, body ) {
		const res = await fetch( WPD.rest + path, {
			method: body ? 'POST' : 'GET',
			headers: { 'X-WP-Nonce': WPD.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: body ? JSON.stringify( body ) : undefined,
		} );
		const json = await res.json().catch( () => ( { message: 'Unexpected response (HTTP ' + res.status + ').' } ) );
		if ( ! res.ok ) {
			throw new Error( json.message || 'HTTP ' + res.status );
		}
		return json;
	}

	function notice( text, type ) {
		msg.innerHTML = text ? '<div class="notice notice-' + ( type || 'info' ) + ' inline"><p>' + text + '</p></div>' : '';
	}

	function busy( on, text ) {
		Object.values( btn ).forEach( ( b ) => ( b.disabled = on || ( b !== btn.load && ! items.length ) ) );
		if ( on ) {
			notice( '<span class="spinner is-active"></span> ' + esc( text ) );
		}
	}

	function selected() {
		return [ ...list.querySelectorAll( 'input.wpd-pick:checked' ) ].map( ( c ) => c.value );
	}

	function forced() {
		return [ ...list.querySelectorAll( 'input.wpd-force:checked' ) ].map( ( c ) => c.value );
	}

	function render() {
		const groups = {};
		items.forEach( ( it ) => ( groups[ it.group ] = groups[ it.group ] || [] ).push( it ) );
		let html = '';
		Object.keys( groups ).forEach( ( g, gi ) => {
			html += '<table class="widefat wpd-table"><thead><tr><td class="check-column"><input type="checkbox" class="wpd-group" data-g="' + gi + '"></td><th>' + esc( g ) + '</th><th>Type</th><th>Status</th><th>Last pushed</th><th></th></tr></thead><tbody>';
			groups[ g ].forEach( ( it ) => {
				const risky = it.sync === 'remote_edited' || it.sync === 'conflict';
				html += '<tr class="wpd-row wpd-row-' + it.sync + '">' +
					'<th class="check-column"><input type="checkbox" class="wpd-pick" data-g="' + gi + '" value="' + esc( it.uuid ) + '"' + ( it.sync === 'blocked' ? ' disabled' : '' ) + '></th>' +
					'<td>' + ( it.edit_link ? '<a href="' + esc( it.edit_link ) + '">' + esc( it.label ) + '</a>' : esc( it.label ) ) +
					( it.status && it.status !== 'publish' ? ' <span class="description">(' + esc( it.status ) + ')</span>' : '' ) +
					( it.note ? '<br><span class="description">' + esc( it.note ) + '</span>' : '' ) + '</td>' +
					'<td>' + esc( it.type_label ) + '</td>' +
					'<td><span class="wpd-badge wpd-' + it.sync + '">' + esc( it.sync_label ) + '</span>' +
					( risky ? '<label class="wpd-force-label"><input type="checkbox" class="wpd-force" value="' + esc( it.uuid ) + '"> overwrite</label>' : '' ) + '</td>' +
					'<td>' + ( it.pushed_at ? esc( it.pushed_at.replace( 'T', ' ' ).slice( 0, 16 ) ) : '—' ) + '</td>' +
					'<td>' + ( it.remote_link ? '<a href="' + esc( it.remote_link ) + '" target="_blank" rel="noopener">on ' + esc( target.name ) + ' ↗</a>' : '' ) + '</td></tr>';
			} );
			html += '</tbody></table>';
		} );
		list.innerHTML = html || '<p>Nothing to push: no enabled sources have content. Check <a href="' + esc( WPD.settings ) + '">Settings</a>.</p>';
		list.querySelectorAll( 'input.wpd-group' ).forEach( ( g ) => g.addEventListener( 'change', () => {
			list.querySelectorAll( 'input.wpd-pick[data-g="' + g.dataset.g + '"]:not(:disabled)' ).forEach( ( c ) => ( c.checked = g.checked ) );
		} ) );
	}

	async function load() {
		out.innerHTML = '';
		items = [];
		list.innerHTML = '';
		busy( true, 'Comparing with ' + env.options[ env.selectedIndex ].text + '…' );
		try {
			const r = await api( '/local/status?env=' + encodeURIComponent( env.value ) );
			items = r.items;
			target = r.remote;
			const counts = {};
			items.forEach( ( it ) => ( counts[ it.sync_label ] = ( counts[ it.sync_label ] || 0 ) + 1 ) );
			let text = 'Connected to <strong>' + esc( target.name ) + '</strong> (' + esc( target.home ) + ', WP Deployer ' + esc( target.plugin ) + '). ' +
				Object.entries( counts ).map( ( [ k, v ] ) => v + ' ' + esc( k.toLowerCase() ) ).join( ' · ' );
			if ( target.stylesheet !== r.local_stylesheet ) {
				text += '<br><strong>Its active theme is ' + esc( target.stylesheet ) + ', not ' + esc( r.local_stylesheet ) + ':</strong> theme settings, Additional CSS and the CSS bundle will be skipped.';
			}
			busy( false );
			notice( text, 'success' );
			render();
			if ( target.setup && ( target.setup.fresh || target.setup.in_progress ) ) {
				await setupPanel();
			}
		} catch ( e ) {
			busy( false );
			notice( esc( e.message ), 'error' );
		}
	}

	function renderResult( r, dry ) {
		const label = { create: 'Create', update: 'Update', unchanged: 'Unchanged', skip: 'Skip' };
		const plan = r.plan || [];
		const changes = plan.filter( ( p ) => p.action !== 'unchanged' );
		const same = plan.length - changes.length;
		let html = '<div class="wpd-panel"><h2>' + ( dry ? 'Preview: what pushing to ' : 'Pushed to ' ) + esc( r.target ) + ( dry ? ' would do' : '' ) + '</h2>';
		if ( r.added_dependencies && r.added_dependencies.length ) {
			html += '<p>Added because the selection needs them and the target doesn\'t have them: ' + r.added_dependencies.map( esc ).join( ', ' ) + '.</p>';
		}
		if ( r.files && r.files.checked ) {
			html += '<p>Files: ' + r.files.to_upload + ' of ' + r.files.checked + ' ' + ( dry ? 'would be uploaded' : 'uploaded' ) + ' (the rest are already there).</p>';
		}
		if ( changes.length ) {
			html += '<table class="widefat striped"><thead><tr><th>Action</th><th>Item</th><th>Kind</th><th>Note</th></tr></thead><tbody>';
			changes.forEach( ( p ) => {
				html += '<tr><td><span class="wpd-badge wpd-act-' + p.action + '">' + esc( label[ p.action ] || p.action ) + '</span></td><td>' + esc( p.label ) + '</td><td>' + esc( p.kind ) + '</td><td>' + esc( p.note ) + '</td></tr>';
			} );
			html += '</tbody></table>';
		} else {
			html += '<p>No changes: everything selected is already on the target.</p>';
		}
		if ( same ) {
			html += '<p class="description">' + same + ' item(s) unchanged (including images already on the target).</p>';
		}
		if ( r.warnings && r.warnings.length ) {
			html += '<div class="notice notice-warning inline"><ul>' + r.warnings.map( ( w ) => '<li>' + esc( w ) + '</li>' ).join( '' ) + '</ul></div>';
		}
		if ( ! dry && target && target.history ) {
			html += '<p><a href="' + esc( target.history ) + '" target="_blank" rel="noopener">History on ' + esc( r.target ) + ' ↗</a> (push #' + esc( r.push_id ) + ', can be rolled back there).</p>';
		}
		out.innerHTML = html + '</div>';
		out.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	}

	async function run( dry ) {
		const uuids = selected();
		if ( ! uuids.length ) {
			notice( 'Tick at least one item.', 'warning' );
			return;
		}
		busy( true, dry ? 'Working out what would change…' : 'Pushing ' + uuids.length + ' item(s) to ' + target.name + '…' );
		try {
			const body = { env: env.value, uuids, dry_run: dry, force: forced() };
			if ( ! dry ) {
				// Upload files in time-boxed rounds first, so a big push never hits a request timeout.
				let last = Infinity;
				for ( ;; ) {
					const f = await api( '/local/push', Object.assign( { phase: 'files' }, body ) );
					if ( ! f.remaining || f.remaining >= last || ! f.uploaded ) {
						break;
					}
					last = f.remaining;
					notice( '<span class="spinner is-active"></span> Uploading files… ' + f.remaining + ' to go' );
				}
				body.phase = 'import';
			}
			const r = await api( '/local/push', body );
			busy( false );
			notice( '' );
			renderResult( r, dry );
			if ( ! dry ) {
				const keep = out.innerHTML;
				await load();
				out.innerHTML = keep;
			}
		} catch ( e ) {
			busy( false );
			notice( esc( e.message ), 'error' );
		}
	}

	/* ---------- Set up new site ---------- */

	const kb = ( n ) => ( n > 1048576 ? ( n / 1048576 ).toFixed( 1 ) + ' MB' : Math.max( 1, Math.round( n / 1024 ) ) + ' KB' );

	async function setupPanel() {
		let plan;
		try {
			plan = await api( '/local/setup/plan?env=' + encodeURIComponent( env.value ) );
		} catch ( e ) {
			notice( esc( e.message ), 'error' );
			return;
		}
		const s = plan.remote.setup;
		const pkgRow = ( p, extra ) => {
			const same = p.there && p.there === p.version;
			return '<label class="wpd-pkg"><input type="checkbox" class="wpd-setup-pkg" value="' + esc( p.key ) + '"' + ( same ? '' : ' checked' ) + '> <strong>' + esc( p.name ) + '</strong> ' + esc( p.version ) +
				' <span class="description">' + kb( p.size ) + ( extra ? ' · ' + extra : '' ) + ( p.there ? ' · on target: ' + esc( p.there ) + ( same ? ' (same, skipped)' : '' ) : '' ) + '</span></label>';
		};
		const others = plan.users.filter( ( u ) => u !== s.user );
		let html = '<div class="wpd-panel wpd-setup"><h2>Set up new site</h2>' +
			'<p><strong>' + esc( plan.remote.name ) + '</strong> (' + esc( plan.remote.home ) + ') is a fresh WordPress install. Set it up as a copy of this site: install the theme and plugins, copy the database and the uploads folder, and fix every address for the new site. Afterwards it is linked, so later pushes only send changes. Anything on ' + esc( plan.remote.name ) + ' now (its sample content) is replaced.</p>' +
			'<div class="notice notice-warning inline"><p><strong>Users are not copied.</strong> Everything created on ' + esc( plan.remote.name ) + ' is credited to its administrator <strong>' + esc( s.user ) + '</strong>.' +
			( others.length ? ' This site has ' + plan.user_count + ' user(s); recreate any you need there (Users › Add New): ' + others.map( esc ).join( ', ' ) + '.' : '' ) + '</p></div>' +
			( s.blocker ? '<div class="notice notice-error inline"><p>' + esc( s.blocker ) + '</p></div>' : '' ) +
			'<h3>Theme</h3>' + plan.themes.map( ( t ) => pkgRow( t, t.role === 'parent' ? 'parent theme' : 'active theme' ) ).join( '' ) +
			'<h3>Plugins</h3>' + plan.plugins.map( ( p ) => pkgRow( p, p.active ? 'activated' : 'installed, left inactive' ) ).join( '' ) +
			( plan.mu_plugins.length ? '<p class="description">Must-use plugins are not copied (' + plan.mu_plugins.map( esc ).join( ', ' ) + '). Plugins that create their own, such as LiveCanvas, recreate them when activated.</p>' : '' ) +
			'<h3>Database and uploads</h3><p>Every database table is copied (posts, pages, ' + plan.media + ' media item(s), menus, settings, and plugin data such as WooCommerce products and orders), except users. Addresses and server paths are rewritten for the new site, including inside serialized and JSON data. The whole uploads folder is copied. The new site keeps its own address, admin email and search engine visibility setting.</p>' +
			'<p><label><input type="checkbox" id="wpd-setup-emails"> Also change email addresses at <code>@' + esc( plan.local_host ) + '</code> to <code>@' + esc( plan.host ) + '</code></label></p>' +
			'<p><label>Type <code>' + esc( plan.host ) + '</code> to confirm: <input type="text" id="wpd-setup-confirm" autocomplete="off"></label> ' +
			'<button type="button" class="button button-primary" id="wpd-setup-run"' + ( s.blocker ? ' disabled' : '' ) + '>Set up ' + esc( plan.remote.name ) + '</button></p>' +
			'<ol id="wpd-setup-log" class="wpd-setup-log"></ol></div>';
		list.insertAdjacentHTML( 'afterbegin', html );
		$( 'wpd-setup-run' ).addEventListener( 'click', () => runSetup( plan ) );
	}

	async function runSetup( plan ) {
		const confirm = $( 'wpd-setup-confirm' ).value.trim().toLowerCase();
		if ( confirm !== plan.host.toLowerCase() ) {
			notice( 'Type ' + esc( plan.host ) + ' exactly to confirm.', 'warning' );
			return;
		}
		const picked = [ ...list.querySelectorAll( 'input.wpd-setup-pkg:checked' ) ].map( ( c ) => c.value );
		const emails = !! ( $( 'wpd-setup-emails' ) && $( 'wpd-setup-emails' ).checked );
		const pkgs = [ ...plan.themes, ...plan.plugins ].filter( ( p ) => picked.includes( p.key ) );
		const log = $( 'wpd-setup-log' );
		const line = ( text ) => {
			const li = document.createElement( 'li' );
			li.innerHTML = '<span class="spinner is-active"></span> ' + esc( text );
			log.appendChild( li );
			return {
				set: ( t ) => ( li.innerHTML = '<span class="spinner is-active"></span> ' + esc( t ) ),
				ok: ( t ) => ( li.innerHTML = '✓ ' + esc( t || text ) ),
				warn: ( t ) => ( li.innerHTML = '<strong class="wpd-warn">! ' + esc( t ) + '</strong>' ),
				fail: ( t ) => ( li.innerHTML = '<strong class="wpd-fail">✗ ' + esc( text ) + ': ' + esc( t ) + '</strong>' ),
			};
		};
		const step = ( name, extra ) => api( '/local/setup/step', Object.assign( { env: env.value, step: name }, extra || {} ) );
		const notes = [];
		Object.values( btn ).forEach( ( b ) => ( b.disabled = true ) );
		$( 'wpd-setup-run' ).disabled = true;
		notice( '' );
		try {
			let l = line( 'Checking ' + plan.remote.name );
			const st = await step( 'start', { confirm, packages: pkgs.map( ( p ) => p.key ) } );
			( st.checks || [] ).forEach( ( c ) => {
				if ( c.level !== 'ok' ) {
					line( c.label )[ c.level === 'fail' ? 'fail' : 'warn' ]( c.label + ': ' + c.detail );
				}
			} );
			if ( ! st.ok ) {
				l.fail( 'pre-flight checks failed (above). Nothing was changed.' );
				throw new Error( 'Fix the failed checks on ' + plan.remote.name + ', then run setup again.' );
			}
			l.ok( plan.remote.name + ' passed the pre-flight checks' );
			for ( const p of pkgs ) {
				l = line( 'Installing ' + p.name + ' (' + kb( p.size ) + ')' );
				try {
					await step( 'package', { key: p.key } );
					l.ok( 'Installed ' + p.name + ' ' + p.version );
				} catch ( e ) {
					l.fail( e.message );
				}
			}
			l = line( 'Activating plugins and theme' );
			const onTarget = plan.plugins.filter( ( p ) => picked.includes( p.key ) || p.there );
			const act = await step( 'activate', { plugins: onTarget.map( ( p ) => p.key.slice( 7 ) ) } );
			const failed = Object.entries( act.failed || {} );
			failed.length ? l.fail( failed.map( ( [ k, v ] ) => k + ': ' + v ).join( '; ' ) ) : l.ok( 'Activated ' + act.activated.length + ' plugin(s); theme ' + act.theme );

			l = line( 'Preparing the database copy' );
			const db = await step( 'db_begin', { emails } );
			const errs = Object.entries( db.errors || {} );
			errs.forEach( ( [ t, e ] ) => notes.push( 'Table ' + t + ' could not be created: ' + e ) );
			l.ok( db.tables.length + ' table(s) to copy (users are not copied)' );
			let done = 0;
			const totalRows = db.tables.reduce( ( n, t ) => n + t.rows, 0 );
			l = line( 'Copying the database' );
			for ( const t of db.tables ) {
				if ( errs.find( ( [ name ] ) => name === t.suffix ) ) {
					continue;
				}
				let offset = 0;
				for ( ;; ) {
					l.set( 'Copying the database: ' + t.suffix + ' (' + Math.min( 100, Math.round( ( done / Math.max( 1, totalRows ) ) * 100 ) ) + '%)' );
					const r = await step( 'db_table', { table: t.suffix, offset } );
					offset = r.offset;
					done += r.rows;
					if ( r.done ) {
						break;
					}
				}
			}
			const sw = await step( 'db_swap' );
			l.ok( 'Copied ' + totalRows + ' row(s) in ' + sw.tables + ' table(s); content credited to ' + plan.remote.setup.user );

			l = line( 'Copying the uploads folder' );
			let cursor = 0;
			let uploaded = 0;
			for ( ;; ) {
				const u = await step( 'uploads', { cursor } );
				cursor = u.cursor;
				uploaded += u.uploaded;
				( u.skipped || [] ).forEach( ( f ) => notes.push( 'Not copied: uploads/' + f ) );
				l.set( 'Copying the uploads folder: ' + cursor + ' of ' + u.total + ' file(s)' );
				if ( u.done ) {
					l.ok( 'Copied ' + uploaded + ' upload file(s) (' + u.total + ' checked)' );
					break;
				}
			}

			l = line( 'Linking items for future pushes' );
			const lk = await step( 'link' );
			l.ok( 'Linked ' + lk.linked + ' item(s)' + ( lk.missing && lk.missing.length ? '; not found: ' + lk.missing.join( ', ' ) : '' ) );

			l = line( 'Finishing' );
			const f = await step( 'finish' );
			( f.deactivated || [] ).forEach( ( d ) => notes.push( 'Switched off ' + d.plugin + ': ' + d.reason + '. Reactivate it under Plugins once you have checked the site.' ) );
			if ( f.language && f.language.indexOf( 'Could not' ) === 0 ) {
				notes.push( f.language );
			}
			l.ok( 'Permalinks flushed' + ( f.caches && f.caches.length ? '; cleared ' + f.caches.join( ', ' ) : '' ) );

			const keep = '<div class="wpd-panel"><h2>' + esc( plan.remote.name ) + ' is set up</h2><p><a href="' + esc( f.home ) + '" target="_blank" rel="noopener">Open ' + esc( f.home ) + ' ↗</a></p>' +
				( notes.length ? '<div class="notice notice-warning inline"><ul>' + notes.map( ( w ) => '<li>' + esc( w ) + '</li>' ).join( '' ) + '</ul></div>' : '' ) +
				'<p>Users were not copied: recreate any you need there (Users › Add New). From now on, push changes as usual.</p></div>';
			await load();
			out.innerHTML = keep;
		} catch ( e ) {
			line( 'Setup stopped' ).fail( e.message );
			notice( 'Setup stopped: ' + esc( e.message ) + ' Run it again once fixed; it starts from the beginning and is safe to repeat while the target is still fresh or within its setup window.', 'error' );
			busy( false );
		}
	}

	function disarm() {
		clearTimeout( armTimer );
		delete btn.push.dataset.armed;
		btn.push.textContent = 'Push';
	}

	btn.load.addEventListener( 'click', load );
	env.addEventListener( 'change', load );
	btn.preview.addEventListener( 'click', () => run( true ) );
	btn.changed.addEventListener( 'click', () => {
		list.querySelectorAll( 'input.wpd-pick' ).forEach( ( c ) => {
			const it = items.find( ( i ) => i.uuid === c.value );
			c.checked = !! it && ( it.sync === 'new' || it.sync === 'changed' );
		} );
	} );
	btn.push.addEventListener( 'click', () => {
		if ( ! selected().length ) {
			notice( 'Tick at least one item.', 'warning' );
			return;
		}
		if ( ! btn.push.dataset.armed ) {
			btn.push.dataset.armed = '1';
			btn.push.textContent = 'Confirm push to ' + ( target ? target.name : 'target' );
			armTimer = setTimeout( disarm, 6000 );
			return;
		}
		disarm();
		run( false );
	} );

	load();
}() );
