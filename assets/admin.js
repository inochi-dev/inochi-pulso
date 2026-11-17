/* Inochi Pulso: scan in chained REST steps with a progress bar. */
( function () {
	'use strict';

	var cfg = window.InochiPulso || {};
	var t = cfg.i18n || {};

	function format( text, values ) {
		var i = 0;
		return String( text ).replace( /%(\d+)\$[sd]|%[sd]/g, function ( m, n ) {
			var v = n ? values[ parseInt( n, 10 ) - 1 ] : values[ i++ ];
			return v === undefined ? m : String( v );
		} );
	}

	function call( path, body ) {
		return window.fetch( cfg.root + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( body || {} )
		} ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( ( data && data.message ) || ( 'HTTP ' + res.status ) );
				}
				return data;
			} );
		} );
	}

	function log( list, text, isError ) {
		var li = document.createElement( 'li' );
		li.textContent = text;
		if ( isError ) {
			li.className = 'ip-log-err';
		}
		list.appendChild( li );
	}

	function start( button ) {
		var box = document.getElementById( 'ip-progress' );
		var bar = box.querySelector( '.ip-bar' );
		var fill = bar.querySelector( 'span' );
		var status = document.getElementById( 'ip-status' );
		var list = document.getElementById( 'ip-log' );
		var running = true;
		var guard = function ( e ) {
			if ( running ) {
				e.preventDefault();
				e.returnValue = t.leave || '';
				return t.leave || '';
			}
		};

		button.disabled = true;
		box.hidden = false;
		list.textContent = '';
		status.textContent = t.starting || '';
		window.addEventListener( 'beforeunload', guard );

		function progress( done, total ) {
			var pct = Math.round( ( done / total ) * 100 );
			fill.style.width = pct + '%';
			bar.setAttribute( 'aria-valuenow', String( pct ) );
		}

		function stop( message ) {
			running = false;
			window.removeEventListener( 'beforeunload', guard );
			button.disabled = false;
			status.textContent = message;
		}

		call( '/scans' ).then( function ( scan ) {
			var steps = scan.steps || [];
			var i = 0;
			var rounds = 0;

			function next() {
				if ( i >= steps.length ) {
					status.textContent = t.finish || '';
					return call( '/scans/' + scan.id + '/finish' ).then( function ( res ) {
						running = false;
						window.removeEventListener( 'beforeunload', guard );
						progress( 1, 1 );
						window.location.href = res.url;
					} );
				}
				var step = steps[ i ];
				status.textContent = format( t.step || '%1$d/%2$d %3$s', [ i + 1, steps.length, step.label ] );
				return call( '/scans/' + scan.id + '/step', { step: step.key } ).then( function ( res ) {
					if ( res.note ) {
						status.textContent = status.textContent + ' · ' + res.note;
					}
					if ( res.error ) {
						log( list, step.label + ' — ' + ( t.stepErr || '' ) + ' ' + res.error, true );
					}
					if ( res.again && rounds < 60 ) {
						rounds++;
						return next(); // Same step, next round.
					}
					rounds = 0;
					log( list, step.label + ' ✓', false );
					i++;
					progress( i, steps.length + 1 );
					return next();
				} );
			}

			return next();
		} ).catch( function ( err ) {
			stop( ( t.failed || '' ) + ' ' + err.message );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'ip-start' );
		if ( button ) {
			button.addEventListener( 'click', function () {
				start( button );
			} );
		}
		document.querySelectorAll( 'form.ip-delete[data-confirm]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				if ( ! window.confirm( form.getAttribute( 'data-confirm' ) ) ) {
					e.preventDefault();
				}
			} );
		} );
	} );
}() );
