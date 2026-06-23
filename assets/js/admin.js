/* global hbmAdmin */
( function () {
	'use strict';

	function esc( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

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

	// -------------------------------------------------------------------------
	// Pre-flight checks — only when there is no active migration.
	// -------------------------------------------------------------------------

	if ( ! hbmAdmin.activeMigration ) {
		const preflightBtn    = document.getElementById( 'hbm-preflight-btn' );
		const preflightResult = document.getElementById( 'hbm-preflight-results' );
		const preflightStart  = document.getElementById( 'hbm-preflight-start' );
		const startForm       = document.getElementById( 'hbm-start-form' );

		if ( preflightBtn && preflightResult && preflightStart ) {
			preflightBtn.addEventListener( 'click', function () {
				const checked = Array.from(
					document.querySelectorAll( '.hbm-site-checkbox:checked' )
				).map( function ( cb ) { return parseInt( cb.value, 10 ); } );

				if ( ! checked.length ) {
					preflightResult.hidden  = false;
					preflightResult.innerHTML = '<div class="notice notice-error"><p>Please select at least one site.</p></div>';
					return;
				}

				preflightBtn.disabled   = true;
				preflightResult.hidden  = false;
				preflightStart.hidden   = true;
				preflightResult.innerHTML = '<div class="hbm-preflight-loading"><span class="spinner is-active"></span> Running pre-flight checks&hellip;</div>';

				fetch( hbmAdmin.preflightEndpoint, {
					method:  'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce':   hbmAdmin.nonce,
					},
					body: JSON.stringify( { site_ids: checked } ),
				} )
					.then( function ( res ) { return res.json(); } )
					.then( function ( data ) {
						preflightBtn.disabled = false;
						if ( data.error ) {
							preflightResult.innerHTML =
								'<div class="notice notice-error"><p>' + esc( data.error ) + '</p></div>';
							return;
						}
						renderPreflightResults( data );
					} )
					.catch( function ( err ) {
						preflightBtn.disabled = false;
						preflightResult.innerHTML =
							'<div class="notice notice-error"><p>Pre-flight check failed: ' + esc( String( err ) ) + '</p></div>';
					} );
			} );
		}

		if ( startForm ) {
			startForm.addEventListener( 'submit', function () {
				// Populate hidden site_id inputs.
				const container = document.getElementById( 'hbm-start-site-ids' );
				container.innerHTML = '';
				document.querySelectorAll( '.hbm-site-checkbox:checked' ).forEach( function ( cb ) {
					const inp  = document.createElement( 'input' );
					inp.type   = 'hidden';
					inp.name   = 'site_ids[]';
					inp.value  = cb.value;
					container.appendChild( inp );
				} );

				// Copy selected policy radios to hidden fields.
				[ 'user', 'site', 'media' ].forEach( function ( type ) {
					const selected = document.querySelector(
						'input[name="hbm_' + type + '_policy"]:checked'
					);
					const hidden = document.getElementById( 'hbm-' + type + '-policy' );
					if ( selected && hidden ) {
						hidden.value = selected.value;
					}
				} );
			} );
		}
	}

	function renderPreflightResults( data ) {
		const summary   = data.summary   || {};
		const conflicts = data.conflicts || {};

		const preflightResult = document.getElementById( 'hbm-preflight-results' );
		const preflightStart  = document.getElementById( 'hbm-preflight-start' );

		let html = '<div class="hbm-preflight-summary">' +
			'<span class="hbm-stat-card"><strong>' + esc( summary.site_count  || 0 ) + '</strong> site' + ( summary.site_count === 1 ? '' : 's' ) + '</span>' +
			'<span class="hbm-stat-card"><strong>' + esc( summary.user_count  || 0 ) + '</strong> users</span>' +
			'<span class="hbm-stat-card"><strong>' + esc( summary.post_count  || 0 ) + '</strong> posts</span>' +
			'<span class="hbm-stat-card"><strong>' + esc( summary.media_count || 0 ) + '</strong> media files</span>' +
		'</div>';

		const userConflicts  = conflicts.users  || [];
		const siteConflicts  = conflicts.sites  || [];
		const mediaConflicts = conflicts.media  || [];

		if ( userConflicts.length ) {
			html += renderConflictSection(
				'User email conflicts',
				userConflicts.length,
				'hbm_user_policy',
				[
					{ value: 'merge',  label: 'Merge — map incoming users to existing accounts (current behavior)' },
					{ value: 'create', label: 'Create — import as a new user with a modified email address' },
				],
				'merge'
			);
		}

		if ( siteConflicts.length ) {
			html += renderConflictSection(
				'Site path conflicts',
				siteConflicts.length,
				'hbm_site_policy',
				[
					{ value: 'generate_new', label: 'Generate new — append -2, -3, etc. to create a unique path (recommended)' },
					{ value: 'use_existing', label: 'Use existing — import content into the already-existing destination subsite' },
				],
				'generate_new'
			);
		}

		if ( mediaConflicts.length ) {
			html += renderConflictSection(
				'Duplicate media files',
				mediaConflicts.length,
				'hbm_media_policy',
				[
					{ value: 'import_all',      label: 'Import all — always import (current behavior)' },
					{ value: 'skip_duplicates', label: 'Skip duplicates — reuse existing files matched by filename' },
				],
				'import_all'
			);
		}

		if ( ! userConflicts.length && ! siteConflicts.length && ! mediaConflicts.length ) {
			html += '<div class="notice notice-success"><p><strong>No conflicts found.</strong> Ready to migrate.</p></div>';
		}

		preflightResult.innerHTML = html;
		preflightStart.hidden = false;
	}

	function renderConflictSection( title, count, radioName, options, defaultValue ) {
		let html = '<div class="hbm-conflict-section">' +
			'<h4>' + esc( title ) + ' <span class="hbm-conflict-badge">' + esc( count ) + '</span></h4>' +
			'<fieldset>';
		options.forEach( function ( opt ) {
			const checked = opt.value === defaultValue ? ' checked' : '';
			html += '<label class="hbm-policy-label">' +
				'<input type="radio" name="' + esc( radioName ) + '" value="' + esc( opt.value ) + '"' + checked + '> ' +
				esc( opt.label ) +
			'</label>';
		} );
		html += '</fieldset></div>';
		return html;
	}

	// -------------------------------------------------------------------------
	// Progress polling — only when a migration is active.
	// -------------------------------------------------------------------------

	if ( ! hbmAdmin.activeMigration ) {
		return;
	}

	const wrap = document.getElementById( 'hbm-progress-wrap' );
	if ( ! wrap ) {
		return;
	}

	const POLL_MS       = 4000;
	const BACKOFF_MS    = 10000;
	const STATUS_LABELS = {
		pending:  'Pending',
		running:  'Running',
		complete: 'Complete',
		failed:   'Failed',
	};

	function pct( offset, total ) {
		return total > 0 ? Math.min( 100, Math.round( offset / total * 100 ) ) : 0;
	}

	function renderSite( site ) {
		const p           = pct( site.stage_offset, site.stage_total );
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
