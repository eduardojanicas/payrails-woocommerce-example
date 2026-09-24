/**
 * Pay-for-order controller: vendored SDK loader → Payrails.init(clientInit) →
 * dropin({ appearance }).mount() → success / failed / pending → confirm or poll
 * the server → redirect to order-received.
 *
 * Classic deferred script; the ESM SDK loader comes in through dynamic import().
 * The browser only ever sends ids to the server. It never sends an outcome the
 * server acts on: "hint" below only changes what this page shows meanwhile.
 */
( function () {
	'use strict';

	var C = window.PayrailsWooPay;
	var root = document.getElementById( 'woo-payrails-pay' );
	if ( ! C || ! root ) {
		return;
	}
	var statusEl = document.getElementById( 'woo-payrails-pay-status' );
	var mountEl = document.getElementById( 'woo-payrails-dropin' );
	var T = C.i18n || {};
	var polling = false;
	var redirecting = false;

	// ---------------------------------------------------------------- UI

	function el( tag, attrs, text ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			n.setAttribute( k, attrs[ k ] );
		} );
		if ( text ) {
			n.textContent = text;
		}
		return n;
	}

	function show( copy, opts ) {
		opts = opts || {};
		statusEl.textContent = '';
		statusEl.setAttribute( 'role', opts.alert ? 'alert' : 'status' );
		if ( ! copy ) {
			return;
		}
		if ( opts.spinner ) {
			statusEl.appendChild( el( 'span', { 'class': 'woo-payrails-pay__spinner', 'aria-hidden': 'true' } ) );
		}
		var box = el( 'div' );
		box.appendChild( el( 'strong', {}, copy[ 0 ] ) );
		var p = el( 'p', {}, copy[ 1 ] || '' );
		if ( opts.extra ) {
			p.appendChild( document.createTextNode( ' ' + opts.extra ) );
		}
		if ( opts.code ) {
			p.appendChild( document.createTextNode( ' ' ) );
			p.appendChild( el( 'code', {}, opts.code ) );
		}
		box.appendChild( p );
		if ( opts.button ) {
			box.appendChild( opts.button );
		}
		statusEl.appendChild( box );
		if ( opts.focus ) {
			statusEl.focus( { preventScroll: false } );
		}
	}

	/**
	 * Pay-panel states: loading | ready | processing | pending | challenge |
	 * success | failed | error | unresolved.
	 */
	var lastPending = '';
	function ui( state, detail ) {
		detail = detail || {};
		var prev = root.getAttribute( 'data-state' );
		if ( 'pending' !== state ) {
			lastPending = '';
		}
		root.setAttribute( 'data-state', state );
		switch ( state ) {
			case 'loading':
				statusEl.textContent = '';
				statusEl.appendChild( el( 'span', { 'class': 'screen-reader-text' }, T.loading ) );
				break;
			case 'ready':
				show( null );
				break;
			case 'processing':
				show( T.processing, { spinner: true } );
				break;
			case 'pending':
				var key = 'pending|' + ( detail.slow ? 1 : 0 ) + '|' + ( detail.verifyUrl || '' );
				if ( lastPending === key && 'pending' === prev ) {
					break; // Unchanged: don't rebuild the banner (keeps focus on the button).
				}
				lastPending = key;
				var verify = null;
				if ( detail.verifyUrl ) {
					verify = el( 'button', { type: 'button', 'class': 'woo-payrails-pay__check-again' }, T.verify );
					verify.addEventListener( 'click', function () {
						window.location.assign( detail.verifyUrl );
					} );
				}
				show( detail.verifyUrl && T.pendingBank ? T.pendingBank : T.pending, { spinner: true, extra: detail.slow ? T.slow : '', button: verify } );
				break;
			case 'challenge':
				show( T.challenge );
				break;
			case 'success':
				show( T.success, { focus: true } );
				break;
			case 'failed':
				show( T.failed, { alert: true, focus: true } );
				break;
			case 'unresolved':
				var again = el( 'button', { type: 'button', 'class': 'woo-payrails-pay__check-again' }, T.checkAgain );
				again.addEventListener( 'click', function () {
					poll( detail.executionId );
				} );
				show( T.unresolved, { alert: true, focus: true, button: again } );
				break;
			default: // error
				var copy = detail.copy || T.error;
				if ( 'PR-VERIFY' === detail.code ) {
					copy = T.review;
				} else if ( 'stale_session' === detail.code ) {
					copy = T.stale;
				}
				show( copy, { alert: true, focus: true, code: detail.code || '' } );
		}
	}

	function goTo( url, delay ) {
		if ( redirecting || ! url ) {
			return;
		}
		redirecting = true;
		window.setTimeout( function () {
			window.location.assign( url );
		}, delay || 0 );
	}

	// ---------------------------------------------------------------- server

	function post( url, params ) {
		var body = new URLSearchParams( params );
		return window.fetch( url, {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { Accept: 'application/json' }
		} ).then( function ( r ) {
			return r.json().catch( function () {
				return { state: 'unknown', code: 'HTTP-' + r.status };
			} );
		} ).catch( function () {
			return { state: 'unknown', code: 'NETWORK' };
		} );
	}

	function confirmCall( executionId ) {
		var p = { order_id: C.orderId, order_key: C.orderKey, nonce: C.nonce };
		if ( executionId ) {
			p.execution_id = executionId;
		}
		return post( C.confirmUrl, p ).then( function ( r ) {
			// If the SDK names an execution we never stored, ask about ours instead.
			if ( r && 'unknown_execution' === r.code && executionId && executionId !== C.executionId ) {
				window.console && console.warn( '[payrails] event executionId is not the stored one; confirming the stored execution' );
				return confirmCall( null );
			}
			return r;
		} );
	}

	function sleep( ms ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, ms );
		} );
	}

	/**
	 * Payrails integration — step 6 (browser side): one confirm POST with ids only;
	 * the server reads the execution from Payrails and decides.
	 */
	function confirm( executionId, hint ) {
		ui( 'processing' );
		return confirmCall( executionId ).then( function ( r ) {
			switch ( r.state ) {
				case 'authorized':
					ui( 'success' );
					return goTo( r.redirect, 'success' === hint ? 800 : 0 );
				case 'failed':
					return ui( 'failed' );
				case 'review':
					return ui( 'error', { code: 'PR-VERIFY' } );
				case 'pending':
				case 'unknown':
					return poll( executionId );
				default:
					return ui( 'error', { code: r.code } );
			}
		} );
	}

	/** Backoff poll: 1.5 s ×1.5 → 6 s cap, "slow" copy after 15 s, ceiling 90 s. */
	function poll( executionId ) {
		if ( polling ) {
			return Promise.resolve();
		}
		polling = true;
		var P = C.poll;
		var t0 = Date.now();
		var wait = P.initialMs;
		ui( 'pending' );
		function tick() {
			if ( Date.now() - t0 >= P.ceilingMs ) {
				polling = false;
				return ui( 'unresolved', { executionId: executionId } );
			}
			return confirmCall( executionId ).then( function ( r ) {
				if ( 'authorized' === r.state ) {
					polling = false;
					ui( 'success' );
					return goTo( r.redirect, 0 );
				}
				if ( 'failed' === r.state ) {
					polling = false;
					return ui( 'failed' );
				}
				if ( 'review' === r.state || 'error' === r.state ) {
					polling = false;
					return ui( 'error', { code: 'review' === r.state ? 'PR-VERIFY' : r.code } );
				}
				// Payrails integration — step 7: the SDK may emit `pending` before a 3DS
				// challenge is shown. When the server reports a waiting 3DS step, the page
				// offers the bank verification as a full-page redirect; the return lands on
				// this page again (returnUrl), where the server resolves the payment.
				var verifyUrl = r.action && 'redirect' === r.action.type ? r.action.url : '';
				if ( 'challenge' !== root.getAttribute( 'data-state' ) ) {
					ui( 'pending', { slow: Date.now() - t0 > P.slowAfterMs, verifyUrl: verifyUrl } );
				}
				var delay = r.retryAfterMs || wait;
				wait = Math.min( wait * P.factor, P.maxMs );
				return sleep( delay ).then( tick );
			} );
		}
		return tick();
	}

	// ---------------------------------------------------------------- live

	function readClientInit() {
		var node = document.getElementById( 'woo-payrails-client-init' );
		if ( ! node ) {
			return null;
		}
		try {
			return JSON.parse( node.textContent );
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * Accessibility: the payment-method logos in the Drop-in sit next to the method's
	 * text label, so they are decorative. Any image in the Drop-in's light DOM without
	 * an alt attribute gets alt="" (re-applied on DOM changes, as the accordion re-renders).
	 */
	function fixDropinImageAlts() {
		var apply = function () {
			var imgs = mountEl.querySelectorAll( 'img:not([alt])' );
			for ( var i = 0; i < imgs.length; i++ ) {
				imgs[ i ].setAttribute( 'alt', '' );
			}
		};
		apply();
		if ( window.MutationObserver ) {
			new MutationObserver( apply ).observe( mountEl, { childList: true, subtree: true } );
		}
	}

	/** With wallets hidden, Card is the only option: open it so the fields show at once. */
	function selectCard() {
		var tries = 0;
		var timer = window.setInterval( function () {
			var radio = document.getElementById( 'payrails-dropin-item-payrails-credit-card-wrapper' );
			if ( radio || ++tries > 40 ) {
				window.clearInterval( timer );
				if ( radio && ! radio.checked ) {
					radio.click();
				}
			}
		}, 250 );
	}

	/**
	 * The SDK session expired: reload with ?payrails_reinit=1 so the server drops its
	 * cached client-init and starts a fresh session. A sessionStorage guard stops a
	 * reload loop: a second expiry within a minute shows a message instead.
	 */
	function reinit() {
		var key = 'payrails_reinit_' + C.orderId;
		var last = 0;
		try {
			last = parseInt( window.sessionStorage.getItem( key ) || '0', 10 );
			window.sessionStorage.setItem( key, String( Date.now() ) );
		} catch ( e ) {
			last = 0;
		}
		if ( last && Date.now() - last < 60000 ) {
			return ui( 'error', { code: 'PR-SESSION', copy: T.expired } );
		}
		var url = new URL( window.location.href );
		url.searchParams.set( 'payrails_reinit', '1' );
		window.location.assign( url.toString() );
	}

	/**
	 * Payrails integration — step 5: mount the Drop-in. Load the SDK, init it with the
	 * server's client-init response, subscribe to success / failed / pending, then mount
	 * with the theme's appearance. Card data goes from the Payrails iframe to Payrails.
	 */
	function mountLive() {
		var clientInit = readClientInit();
		if ( ! clientInit ) {
			return ui( 'error', { code: 'PR-RESPONSE' } );
		}
		return import( C.sdkUrl ).then( function ( mod ) {
			var Payrails = mod.Payrails;
			return Payrails.init( clientInit, {
				events: {
					onClientInitialized: function () {
						if ( 'loading' === root.getAttribute( 'data-state' ) ) {
							ui( 'ready' );
						}
					}
				},
				returnInfo: { success: C.returnUrl, error: C.returnUrl, cancel: C.returnUrl, pending: C.returnUrl }
			} ).then( function ( payrails ) {
				payrails.on( 'requestStart', function () {
					ui( 'processing' );
				} );
				payrails.on( 'actionRequired', function ( e ) {
					window.console && console.info( '[payrails] actionRequired', e && e.kind );
					ui( 'challenge' );
				} );
				payrails.on( 'success', function ( e ) {
					confirm( e && e.executionId, 'success' );
				} );
				payrails.on( 'pending', function ( e ) {
					poll( ( e && e.executionId ) || C.executionId );
				} );
				payrails.on( 'failed', function ( e ) {
					window.console && console.warn( '[payrails] failed', e && e.data && e.data.code );
					confirm( ( e && e.executionId ) || C.executionId, 'failed' );
				} );
				payrails.on( 'sessionExpired', reinit );
				var options = { translations: { paymentResult: { fail: T.dropinFail } } };
				if ( C.guest ) {
					// Guests: no "save card" checkbox, no silent storing, no stored cards listed.
					options.paymentMethodsConfiguration = {
						cards: { showStoredInstruments: false, showStoreInstrumentCheckbox: false, alwaysStoreInstrument: false }
					};
				}
				if ( C.appearance ) {
					options.appearance = C.appearance;
				}
				fixDropinImageAlts();
				payrails.dropin( options ).mount( '#woo-payrails-dropin' );
				if ( C.cardsOnly ) {
					selectCard();
				}
				if ( 'loading' === root.getAttribute( 'data-state' ) ) {
					ui( 'ready' );
				}
			}, function ( err ) {
				window.console && console.error( '[payrails] init failed', err && ( err.name || err.message ) );
				ui( 'error', { code: 'PR-SDK-INIT' } );
			} );
		}, function ( err ) {
			window.console && console.error( '[payrails] SDK load failed', err && err.message );
			ui( 'error', { code: 'PR-SDK-LOAD' } );
		} );
	}

	// ---------------------------------------------------------------- main

	// Payrails integration — step 7: "Try again" after a decline or an error reloads the
	// page; the server then creates a fresh execution for the (failed) order, so the next
	// card starts a clean Drop-in session (each session belongs to one execution).
	var retry = root.querySelector( '[data-action="retry"]' );
	if ( retry ) {
		retry.addEventListener( 'click', function () {
			window.location.reload();
		} );
	}

	// Drop the one-shot reinit flag from the address bar, so a manual reload does not repeat it.
	if ( window.history && window.history.replaceState && /[?&]payrails_reinit=/.test( window.location.search ) ) {
		var clean = new URL( window.location.href );
		clean.searchParams.delete( 'payrails_reinit' );
		window.history.replaceState( null, '', clean.toString() );
	}

	switch ( C.mode ) {
		case 'error':
			return ui( 'error', { code: C.errorCode } );
		case 'paid':
			ui( 'success' );
			return goTo( C.redirect, 800 );
		case 'pending':
			return poll( C.executionId );
		default:
			ui( 'loading' );
			return mountLive();
	}
} )();
