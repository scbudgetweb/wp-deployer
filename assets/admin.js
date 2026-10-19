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
			const r = await api( '/local/push', { env: env.value, uuids, dry_run: dry, force: forced() } );
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
