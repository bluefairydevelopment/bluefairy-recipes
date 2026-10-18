/**
 * Public recipe pages: the filter form submits itself 600 ms after a change
 * (selects at once, typing debounced), and the print button prints.
 *
 * @package BlueFairy\Recipes
 */
( function () {
	'use strict';

	var DEBOUNCE = 600;

	document.querySelectorAll( '[data-bfrc-filters]' ).forEach( function ( form ) {
		var timer = null;
		function submitSoon( delay ) {
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				if ( typeof form.requestSubmit === 'function' ) {
					form.requestSubmit();
				} else {
					form.submit();
				}
			}, delay );
		}
		form.querySelectorAll( 'select' ).forEach( function ( select ) {
			select.addEventListener( 'change', function () {
				submitSoon( 0 );
			} );
		} );
		form.querySelectorAll( 'input[type=search]' ).forEach( function ( input ) {
			input.addEventListener( 'input', function () {
				submitSoon( DEBOUNCE );
			} );
		} );
	} );

	document.querySelectorAll( '[data-bfrc-print]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			window.print();
		} );
	} );
}() );
