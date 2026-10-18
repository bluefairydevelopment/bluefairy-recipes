/**
 * Public recipe pages: the filter form submits itself 600 ms after the last
 * change (selects and typing alike, so arrow keys and half-typed words get
 * a chance to finish), empty fields are left out of the URL, focus returns
 * to the search box after a keyword search, and the print button prints.
 *
 * @package BlueFairy\Recipes
 */
( function () {
	'use strict';

	var DEBOUNCE   = 600;
	var FOCUS_FLAG = 'bfrcFocus';
	var FOCUS_TTL  = 10000; // A flag older than this was written for a navigation that never happened.

	// A disabled field is not submitted, which keeps empty fields out of the URL. The page
	// normally unloads at once, but a cancelled submit, or a Back navigation restoring the
	// page from the browser's cache, would leave the fields dead: so every field we disable
	// is marked and re-enabled on pagehide and pageshow.
	function dropEmptyFields( form ) {
		form.querySelectorAll( 'select, input[type=search]' ).forEach( function ( field ) {
			if ( field.value === '' && ! field.disabled ) {
				field.disabled = true;
				field.setAttribute( 'data-bfrc-disabled', '1' );
			}
		} );
	}

	function restoreFields() {
		document.querySelectorAll( '[data-bfrc-filters] [data-bfrc-disabled]' ).forEach( function ( field ) {
			field.disabled = false;
			field.removeAttribute( 'data-bfrc-disabled' );
		} );
	}

	// The focus flag lives in sessionStorage: it is set only when typing caused the submit,
	// so a shared link, a select or the Filter button never steal focus (or open a keyboard).
	function readFlag( name ) {
		try {
			var value = window.sessionStorage.getItem( name );
			window.sessionStorage.removeItem( name );
			return value;
		} catch ( e ) {
			return null;
		}
	}

	function writeFlag( name, value ) {
		try {
			window.sessionStorage.setItem( name, value );
		} catch ( e ) {
			// Storage unavailable: the search still runs, focus is simply not restored.
		}
	}

	// The flag is "name:timestamp": a stale one (a cancelled search, a detour through other pages)
	// must not steal focus on a later recipe view.
	var focusName = ( function () {
		var raw = readFlag( FOCUS_FLAG );
		if ( ! raw ) {
			return null;
		}
		var at = raw.lastIndexOf( ':' );
		if ( at === -1 || Date.now() - Number( raw.slice( at + 1 ) ) > FOCUS_TTL ) {
			return null;
		}
		return raw.slice( 0, at );
	}() );

	document.querySelectorAll( '[data-bfrc-filters]' ).forEach( function ( form ) {
		var timer = null;
		function submitSoon( focusField ) {
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				if ( focusField ) {
					writeFlag( FOCUS_FLAG, focusField + ':' + Date.now() );
				}
				dropEmptyFields( form );
				if ( typeof form.requestSubmit === 'function' ) {
					form.requestSubmit();
				} else {
					form.submit();
				}
			}, DEBOUNCE );
		}
		form.addEventListener( 'submit', function ( event ) {
			window.clearTimeout( timer );
			dropEmptyFields( form );
			var remaining = Array.prototype.filter.call( form.querySelectorAll( 'select, input[type=search]' ), function ( field ) {
				return ! field.disabled;
			} );
			var action = form.getAttribute( 'action' ); // Not form.action: a field named "action" would shadow it.
			if ( remaining.length === 0 && action ) {
				event.preventDefault(); // Nothing to send: go to the plain archive URL rather than "…/?".
				window.location.assign( action );
			}
		} );
		form.querySelectorAll( 'select' ).forEach( function ( select ) {
			select.addEventListener( 'change', function () {
				submitSoon( select.name ); // The page reloads: focus goes back to this select, not to the top of the page.
			} );
			if ( focusName === select.name && document.activeElement === document.body ) {
				select.focus();
			}
		} );
		form.querySelectorAll( 'input[type=search]' ).forEach( function ( input ) {
			input.addEventListener( 'input', function ( event ) {
				if ( event.isComposing ) {
					return; // Mid-composition (IME): compositionend below submits the committed text.
				}
				submitSoon( input.name );
			} );
			// Chrome and Safari send the composition's last input event with isComposing still true and
			// nothing after it, so the committed text is submitted from here.
			input.addEventListener( 'compositionend', function () {
				submitSoon( input.name );
			} );
			// After a keyword search the page has reloaded: put the caret back where the visitor was typing
			// (also when they cleared the box: the flag says typing caused the reload).
			if ( focusName === input.name && document.activeElement === document.body ) {
				input.focus();
				if ( typeof input.setSelectionRange === 'function' ) {
					input.setSelectionRange( input.value.length, input.value.length );
				}
			}
		} );
	} );

	window.addEventListener( 'pagehide', restoreFields );
	window.addEventListener( 'pageshow', restoreFields );

	document.querySelectorAll( '[data-bfrc-print]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			window.print();
		} );
	} );
}() );
