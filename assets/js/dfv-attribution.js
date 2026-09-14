/**
 * Divi Form Vault - front-end capture.
 *
 * Three small jobs, one tiny file, no dependencies:
 *   1. ATTRIBUTION - capture UTM params + landing page + referrer into a
 *      first-party cookie (first-touch kept forever within the cookie's
 *      lifetime, last-touch updated whenever a new UTM set arrives). The
 *      server reads this cookie when a form submits.
 *   2. HONEYPOT - inject a hidden field into every Divi Contact Form so bots
 *      that blind-fill forms reveal themselves. We add an extra input
 *      client-side only; Divi's own markup and processing are untouched.
 *   3. GA4 - after a captured submission the server leaves a one-shot event
 *      cookie; we read it, push generate_lead to GTM/gtag, and delete it.
 *
 * @package DiviFormVault
 */
( function () {
	'use strict';

	var cfg = window.dfvConfig || {};

	/* ---------------------------------------------------------------- *
	 * Cookie helpers (first-party, path=/)
	 * ---------------------------------------------------------------- */

	function readCookie( name ) {
		var m = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );
		return m ? decodeURIComponent( m[ 1 ] ) : '';
	}

	function writeCookie( name, value, days ) {
		var maxAge = Math.max( 1, parseInt( days, 10 ) || 90 ) * 86400;
		document.cookie = name + '=' + encodeURIComponent( value ) + '; path=/; max-age=' + maxAge + '; SameSite=Lax';
	}

	function deleteCookie( name ) {
		document.cookie = name + '=; path=/; max-age=0; SameSite=Lax';
	}

	/* ---------------------------------------------------------------- *
	 * 1. Attribution
	 * ---------------------------------------------------------------- */

	function currentUtms() {
		var params = new URLSearchParams( window.location.search );
		var map = { s: 'utm_source', m: 'utm_medium', c: 'utm_campaign', t: 'utm_term', n: 'utm_content' };
		var utms = {};
		var found = false;
		Object.keys( map ).forEach( function ( short ) {
			var v = params.get( map[ short ] );
			if ( v ) {
				utms[ short ] = v.slice( 0, 180 );
				found = true;
			}
		} );
		return found ? utms : null;
	}

	function captureAttribution() {
		var attr = {};
		try {
			attr = JSON.parse( readCookie( 'dfv_attr' ) || '{}' ) || {};
		} catch ( e ) {
			attr = {};
		}

		// Landing page + referrer: first page of the (cookie-lifetime) session.
		if ( ! attr.lp ) {
			attr.lp = String( window.location.href ).slice( 0, 500 );
			attr.r = String( document.referrer || '' ).slice( 0, 500 );
		}

		var utms = currentUtms();
		if ( utms ) {
			attr.l = utms;              // Last touch: always the newest set.
			if ( ! attr.f ) {
				attr.f = utms;          // First touch: written once.
			}
		}

		writeCookie( 'dfv_attr', JSON.stringify( attr ), cfg.cookieDays );
	}

	/* ---------------------------------------------------------------- *
	 * 2. Honeypot (injected client-side; Divi markup untouched on the server)
	 * ---------------------------------------------------------------- */

	function injectHoneypot() {
		if ( ! cfg.honeypot ) {
			return;
		}
		var forms = document.querySelectorAll( '.et_pb_contact_form' );
		Array.prototype.forEach.call( forms, function ( form ) {
			if ( form.querySelector( 'input[name="dfv_hp_website"]' ) ) {
				return;
			}
			var input = document.createElement( 'input' );
			input.type = 'text';
			input.name = 'dfv_hp_website';
			input.value = '';
			input.tabIndex = -1;
			input.autocomplete = 'off';
			input.setAttribute( 'aria-hidden', 'true' );
			// Visually hidden but not display:none (some bots skip hidden inputs).
			input.style.cssText = 'position:absolute!important;left:-9999px!important;height:1px;width:1px;opacity:0;';
			form.appendChild( input );
		} );
	}

	/* ---------------------------------------------------------------- *
	 * 3. GA4 generate_lead (one-shot event cookie set by the server)
	 * ---------------------------------------------------------------- */

	function fireGA4() {
		// Same-page channel first (the server injects window.dfvLeadEvent when
		// the submission was captured while rendering this very response).
		var params = null;
		if ( window.dfvLeadEvent && 'object' === typeof window.dfvLeadEvent ) {
			params = window.dfvLeadEvent;
			window.dfvLeadEvent = null;
			deleteCookie( 'dfv_lead_evt' ); // Kill the fallback so it cannot double-fire.
		} else {
			var raw = readCookie( 'dfv_lead_evt' );
			if ( ! raw ) {
				return;
			}
			deleteCookie( 'dfv_lead_evt' ); // One shot - never re-fire on reload.
			try {
				params = JSON.parse( raw ) || {};
			} catch ( e ) {
				return;
			}
		}

		if ( ! params || ! cfg.ga4 || ! cfg.ga4.enabled ) {
			return;
		}

		// Same dispatch preference as our other IH lead sources: our own gtag
		// when a Measurement ID is configured, else the site's GTM dataLayer,
		// else any ambient gtag.
		if ( cfg.ga4.measurementId && typeof window.gtag === 'function' ) {
			window.gtag( 'event', 'generate_lead', params );
		} else if ( window.dataLayer && typeof window.dataLayer.push === 'function' ) {
			params.event = 'generate_lead';
			window.dataLayer.push( params );
		} else if ( typeof window.gtag === 'function' ) {
			window.gtag( 'event', 'generate_lead', params );
		}
	}

	/* ---------------------------------------------------------------- */

	function boot() {
		captureAttribution();
		injectHoneypot();
		fireGA4();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
