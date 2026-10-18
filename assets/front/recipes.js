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

	var DEBOUNCE = 600;

	function dropEmptyFields( form ) {
		form.querySelectorAll( 'select, input[type=search]' ).forEach( function ( field ) {
			if ( field.value === '' ) {
				field.disabled = true; // A disabled field is not submitted; the page reloads anyway.
			}
		} );
	}

	document.querySelectorAll( '[data-bfrc-filters]' ).forEach( function ( form ) {
		var timer = null;
		function submitSoon() {
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				dropEmptyFields( form );
				if ( typeof form.requestSubmit === 'function' ) {
					form.requestSubmit();
				} else {
					form.submit();
				}
			}, DEBOUNCE );
		}
		form.addEventListener( 'submit', function () {
			window.clearTimeout( timer );
			dropEmptyFields( form );
		} );
		form.querySelectorAll( 'select' ).forEach( function ( select ) {
			select.addEventListener( 'change', submitSoon );
		} );
		form.querySelectorAll( 'input[type=search]' ).forEach( function ( input ) {
			input.addEventListener( 'input', submitSoon );
			// After a keyword search the page has reloaded: put the caret back where the visitor was typing.
			if ( input.value !== '' && document.activeElement === document.body && window.location.search.indexOf( input.name + '=' ) !== -1 ) {
				input.focus();
				if ( typeof input.setSelectionRange === 'function' ) {
					input.setSelectionRange( input.value.length, input.value.length );
				}
			}
		} );
	} );

	document.querySelectorAll( '[data-bfrc-print]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			window.print();
		} );
	} );
}() );
