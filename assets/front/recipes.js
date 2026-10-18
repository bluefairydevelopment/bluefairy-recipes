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

	var focusName = readFlag( FOCUS_FLAG );

	document.querySelectorAll( '[data-bfrc-filters]' ).forEach( function ( form ) {
		var timer = null;
		function submitSoon( fromTyping ) {
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				if ( fromTyping ) {
					writeFlag( FOCUS_FLAG, fromTyping );
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
			if ( remaining.length === 0 && form.action ) {
				event.preventDefault(); // Nothing to send: go to the plain archive URL rather than "…/?".
				window.location.assign( form.action );
			}
		} );
		form.querySelectorAll( 'select' ).forEach( function ( select ) {
			select.addEventListener( 'change', function () {
				submitSoon( '' );
			} );
		} );
		form.querySelectorAll( 'input[type=search]' ).forEach( function ( input ) {
			input.addEventListener( 'input', function ( event ) {
				if ( event.isComposing ) {
					return; // Mid-composition (IME): wait for the composed text.
				}
				submitSoon( input.name );
			} );
			// After a keyword search the page has reloaded: put the caret back where the visitor was typing.
			if ( focusName === input.name && input.value !== '' && document.activeElement === document.body ) {
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
