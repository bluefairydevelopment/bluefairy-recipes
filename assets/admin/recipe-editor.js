/**
 * The recipe editor's repeatable rows: add, remove, move (buttons or drag),
 * renumber the field names, and show only the extra fields of a row's type.
 *
 * @package BlueFairy\Recipes
 */
( function () {
	'use strict';

	var rows = window.bfrcRecipeRows;
	var config = window.bfrcRecipeEditor || { maxRows: 200, i18n: {} };

	function container( el ) {
		return el.closest( '[data-bfrc-rows]' );
	}

	function listOf( box ) {
		return box.querySelector( 'tbody, .bfrc-rows__list' );
	}

	function rowsOf( box ) {
		return Array.prototype.slice.call( listOf( box ).children );
	}

	function renumberAll( box ) {
		rowsOf( box ).forEach( function ( row, index ) {
			row.querySelectorAll( '[name]' ).forEach( function ( field ) {
				field.name = rows.renumber( field.name, index );
			} );
		} );
	}

	function showExtras( row ) {
		var select = row.querySelector( '.bfrc-rows__type' );
		if ( ! select ) {
			return;
		}
		row.querySelectorAll( '.bfrc-rows__extra' ).forEach( function ( field ) {
			field.hidden = field.getAttribute( 'data-bfrc-type' ) !== select.value;
		} );
	}

	function addRow( box ) {
		var max = parseInt( box.getAttribute( 'data-bfrc-max' ), 10 ) || config.maxRows;
		if ( ! rows.canAdd( rowsOf( box ).length, max ) ) {
			window.wp && window.wp.a11y && window.wp.a11y.speak( config.i18n.maxRows || '' );
			return;
		}
		var template = box.querySelector( '.bfrc-rows__template' );
		var fragment = template.content.cloneNode( true );
		listOf( box ).appendChild( fragment );
		renumberAll( box );
		var added = rowsOf( box ).pop();
		showExtras( added );
		var first = added.querySelector( 'input:not([type=hidden]), textarea' );
		if ( first ) {
			first.focus();
		}
	}

	function moveRow( box, row, delta ) {
		var all = rowsOf( box );
		var from = all.indexOf( row );
		var order = rows.move( all, from, from + delta );
		var list = listOf( box );
		order.forEach( function ( r ) {
			list.appendChild( r );
		} );
		renumberAll( box );
		row.querySelector( '.bfrc-rows__' + ( delta < 0 ? 'up' : 'down' ) ).focus();
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		var box = container( target );
		if ( ! box ) {
			return;
		}
		if ( target.classList.contains( 'bfrc-rows__add' ) ) {
			addRow( box );
		} else if ( target.classList.contains( 'bfrc-rows__remove' ) ) {
			target.closest( '.bfrc-rows__row' ).remove();
			renumberAll( box );
		} else if ( target.classList.contains( 'bfrc-rows__up' ) ) {
			moveRow( box, target.closest( '.bfrc-rows__row' ), -1 );
		} else if ( target.classList.contains( 'bfrc-rows__down' ) ) {
			moveRow( box, target.closest( '.bfrc-rows__row' ), 1 );
		}
	} );

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.classList.contains( 'bfrc-rows__type' ) ) {
			showExtras( event.target.closest( '.bfrc-rows__row' ) );
		}
	} );

	var dragging = null;
	document.addEventListener( 'dragstart', function ( event ) {
		var row = event.target.closest && event.target.closest( '.bfrc-rows__row' );
		if ( row ) {
			dragging = row;
			event.dataTransfer.effectAllowed = 'move';
		}
	} );
	document.addEventListener( 'dragover', function ( event ) {
		var row = event.target.closest && event.target.closest( '.bfrc-rows__row' );
		if ( dragging && row && row !== dragging && container( row ) === container( dragging ) ) {
			event.preventDefault();
			var all = rowsOf( container( row ) );
			var from = all.indexOf( dragging );
			var to = all.indexOf( row );
			listOf( container( row ) ).insertBefore( dragging, from < to ? row.nextSibling : row );
		}
	} );
	document.addEventListener( 'dragend', function () {
		if ( dragging ) {
			renumberAll( container( dragging ) );
			dragging = null;
		}
	} );

	document.querySelectorAll( '.bfrc-rows__row' ).forEach( showExtras );

	/*
	 * Block editor: the meta boxes are posted in the background and the page
	 * is not reloaded, so the server's admin notice never shows. After each
	 * meta-box save, read the stored video URL back and warn inline when a
	 * typed URL was refused.
	 */
	function videoNotice( field, show ) {
		var box = field.closest( '.bfrc-editor--media' );
		var existing = box.querySelector( '.bfrc-video-notice' );
		if ( ! show ) {
			if ( existing ) {
				existing.remove();
			}
			return;
		}
		if ( ! existing ) {
			existing = document.createElement( 'div' );
			existing.className = 'notice notice-warning inline bfrc-video-notice';
			existing.setAttribute( 'role', 'alert' );
			box.insertBefore( existing, field.closest( 'p' ).nextSibling );
		}
		existing.textContent = ( config.i18n.videoRejected || '' ).replace( '%s', field.value.trim() );
	}

	function checkVideo() {
		var field = document.getElementById( 'bfrc-video-url' );
		if ( ! field ) {
			return;
		}
		if ( field.value.trim() === '' ) {
			videoNotice( field, false );
			return;
		}
		window.wp.apiFetch( { path: config.restPath + '?context=edit&_fields=meta' } ).then( function ( post ) {
			var stored = post && post.meta ? post.meta[ config.videoKey ] : '';
			videoNotice( field, rows.videoRejected( field.value, stored ) );
		} );
	}

	if ( config.restPath && window.wp && window.wp.data && window.wp.apiFetch ) {
		var wasSaving = false;
		window.wp.data.subscribe( function () {
			var store = window.wp.data.select( 'core/edit-post' );
			if ( ! store || typeof store.isSavingMetaBoxes !== 'function' ) {
				return;
			}
			var saving = store.isSavingMetaBoxes();
			if ( wasSaving && ! saving ) {
				checkVideo();
			}
			wasSaving = saving;
		} );
	}
}() );
