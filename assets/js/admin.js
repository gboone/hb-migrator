/* global hbmAdmin */
( function () {
	'use strict';

	const wrap = document.getElementById( 'hbm-wrap' );
	if ( ! wrap ) {
		return;
	}

	// Confirm guard for the Reset Export button.
	const resetForm = document.getElementById( 'hbm-reset-form' );
	if ( resetForm ) {
		resetForm.addEventListener( 'submit', function ( e ) {
			const btn = resetForm.querySelector( '.hbm-reset-btn' );
			const msg = ( btn && btn.dataset.confirm ) || 'Are you sure you want to reset the export?';
			if ( ! window.confirm( msg ) ) {
				e.preventDefault();
			}
		} );
	}

	// Only start polling when the page reports an active export.
	if ( ! wrap.dataset.exportRunning ) {
		return;
	}

	const POLL_INTERVAL_MS = 5000;
	const STATUS_LABELS    = {
		pending:  'Pending',
		running:  'Running',
		complete: 'Complete',
		failed:   'Failed',
	};

	function formatNumber( n ) {
		return Number( n ).toLocaleString();
	}

	function updateStageRow( stage, data ) {
		const row = document.getElementById( 'hbm-stage-' + stage );
		if ( ! row ) {
			return;
		}

		row.dataset.status = data.status;

		const statusEl = row.querySelector( '.hbm-status' );
		if ( statusEl ) {
			statusEl.textContent  = STATUS_LABELS[ data.status ] || data.status;
			statusEl.className    = 'hbm-status hbm-status-' + data.status;
		}

		const pct  = data.total_items > 0 ? Math.min( 100, Math.round( data.batch_offset / data.total_items * 100 ) ) : 0;
		const fill = row.querySelector( '.hbm-progress-fill' );
		const bar  = row.querySelector( '.hbm-progress-bar' );
		if ( fill ) {
			fill.style.width = pct + '%';
		}
		if ( bar ) {
			bar.setAttribute( 'aria-valuenow', pct );
		}

		const label = row.querySelector( '.hbm-progress-label' );
		if ( label ) {
			label.textContent = data.total_items > 0
				? formatNumber( data.batch_offset ) + ' / ' + formatNumber( data.total_items )
				: '—';
		}

		// Show or clear error messages below failed stage rows.
		let errEl = row.querySelector( '.hbm-error-message' );
		if ( 'failed' === data.status && data.error_message ) {
			if ( ! errEl ) {
				const td  = row.querySelector( 'td:last-child' );
				errEl     = document.createElement( 'p' );
				errEl.className = 'hbm-error-message';
				td && td.appendChild( errEl );
			}
			errEl.textContent = data.error_message;
		} else if ( errEl ) {
			errEl.remove();
		}
	}

	function pollProgress() {
		wp.apiFetch( {
			url:     hbmAdmin.progressEndpoint,
			headers: { 'X-WP-Nonce': hbmAdmin.nonce },
		} )
			.then( function ( data ) {
				const stages = data.stages || {};
				Object.keys( stages ).forEach( function ( stage ) {
					updateStageRow( stage, stages[ stage ] );
				} );

				if ( data.is_complete || data.is_failed ) {
					// Stop polling and reload to show download links or retry buttons.
					window.location.reload();
					return;
				}

				if ( data.is_running ) {
					setTimeout( pollProgress, POLL_INTERVAL_MS );
				}
			} )
			.catch( function () {
				// On network error, keep retrying.
				setTimeout( pollProgress, POLL_INTERVAL_MS * 2 );
			} );
	}

	// Kick off the first poll after a short delay.
	setTimeout( pollProgress, POLL_INTERVAL_MS );
}() );
