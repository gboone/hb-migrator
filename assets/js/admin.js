/* global hbmAdmin */
( function () {
	'use strict';

	// Copy API key button.
	const copyBtn = document.getElementById( 'hbm-copy-key' );
	if ( copyBtn ) {
		copyBtn.addEventListener( 'click', function () {
			const input = document.getElementById( 'hbm-api-key' );
			if ( input ) {
				input.select();
				document.execCommand( 'copy' );
				copyBtn.textContent = 'Copied!';
				setTimeout( function () {
					copyBtn.textContent = 'Copy';
				}, 2000 );
			}
		} );
	}

	// Progress polling — only when a migration is active.
	if ( ! hbmAdmin.activeMigration ) {
		return;
	}

	const wrap = document.getElementById( 'hbm-progress-wrap' );
	if ( ! wrap ) {
		return;
	}

	const POLL_MS        = 4000;
	const BACKOFF_MS     = 10000;
	const STATUS_LABELS  = {
		pending:  'Pending',
		running:  'Running',
		complete: 'Complete',
		failed:   'Failed',
	};

	function pct( offset, total ) {
		return total > 0 ? Math.min( 100, Math.round( offset / total * 100 ) ) : 0;
	}

	function renderSite( site ) {
		const p = pct( site.stage_offset, site.stage_total );
		const statusLabel = STATUS_LABELS[ site.status ] || site.status;
		const stage       = site.current_stage ? ' (' + site.current_stage + ')' : '';
		const errHtml     = ( site.status === 'failed' && site.error_message )
			? '<p class="hbm-error-message">' + esc( site.error_message ) + '</p>'
			: '';
		return '<tr>' +
			'<td>' + esc( site.source_domain ) + '</td>' +
			'<td>' + esc( site.dest_path ) + '</td>' +
			'<td><span class="hbm-status hbm-status-' + esc( site.status ) + '">' + esc( statusLabel ) + esc( stage ) + '</span></td>' +
			'<td>' +
				'<div class="hbm-progress-bar"><div class="hbm-progress-fill" style="width:' + p + '%"></div></div>' +
				'<span class="hbm-progress-label">' + ( site.stage_total > 0 ? site.stage_offset + ' / ' + site.stage_total : '&mdash;' ) + '</span>' +
			'</td>' +
			'<td>' + errHtml + '</td>' +
		'</tr>';
	}

	function esc( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function render( data ) {
		const sites   = data.sites || [];
		const allDone = sites.length > 0 && sites.every( function ( s ) { return s.status === 'complete'; } );
		const anyFail = sites.some( function ( s ) { return s.status === 'failed'; } );

		let html = '<table class="widefat hbm-site-table">' +
			'<thead><tr><th>Source</th><th>Destination path</th><th>Status</th><th>Progress</th><th></th></tr></thead>' +
			'<tbody>';
		sites.forEach( function ( s ) { html += renderSite( s ); } );
		html += '</tbody></table>';

		if ( allDone ) {
			html += '<div class="notice notice-success"><p><strong>Migration complete!</strong></p></div>';
		} else if ( anyFail ) {
			html += '<div class="notice notice-error"><p><strong>One or more sites failed.</strong> Check error messages above.</p></div>';
		}

		wrap.innerHTML = html;
		return allDone || anyFail;
	}

	function poll() {
		fetch( hbmAdmin.statusEndpoint, {
			headers: { 'X-WP-Nonce': hbmAdmin.nonce },
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				const done = render( data );
				if ( ! done ) {
					setTimeout( poll, POLL_MS );
				}
			} )
			.catch( function () {
				setTimeout( poll, BACKOFF_MS );
			} );
	}

	setTimeout( poll, POLL_MS );
}() );
