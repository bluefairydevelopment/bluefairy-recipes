/**
 * The recipe editor's repeatable rows: add, remove, move (buttons or drag by
 * the handle), renumber the field names and accessible labels, enable only
 * the extra fields of a row's type, warn before the form outgrows PHP's
 * input ceiling, and (block editor) report a refused video URL inline.
 *
 * Exposes `init( document, window )` so the DOM wiring can run under Node
 * with a DOM implementation in `node --test`.
 *
 * @package BlueFairy\Recipes
 */
( function ( root ) {
	'use strict';

	function init( doc, win ) {
		var rows = win.bfrcRecipeRows;
		var config = win.bfrcRecipeEditor || { maxRows: '200', maxInputVars: '0', i18n: {} };
		var i18n = config.i18n || {};
		var maxRows = parseInt( config.maxRows, 10 ) || 200;
		var maxInputVars = parseInt( config.maxInputVars, 10 ) || 0;

		function speak( text ) {
			if ( win.wp && win.wp.a11y && typeof win.wp.a11y.speak === 'function' ) {
				win.wp.a11y.speak( text );
			}
		}

		function container( el ) {
			return el && el.closest ? el.closest( '[data-bfrc-rows]' ) : null;
		}

		function listOf( box ) {
			return box.querySelector( 'tbody, .bfrc-rows__list' );
		}

		function rowsOf( box ) {
			return Array.prototype.slice.call( listOf( box ).children );
		}

		function labelsFor( box ) {
			var all = i18n.rowLabels || {};
			return all[ box.getAttribute( 'data-bfrc-rows' ) ] || {};
		}

		function relabel( box, row, index ) {
			var labels = labelsFor( box );
			row.querySelectorAll( '[name]' ).forEach( function ( field ) {
				var match = /\[([a-z_]+)\]$/.exec( field.name );
				var key = match ? match[ 1 ] : '';
				if ( field.classList.contains( 'bfrc-rows__extra' ) ) {
					field.setAttribute( 'aria-label', rows.rowLabel( labels.extra, index, field.getAttribute( 'placeholder' ) || field.getAttribute( 'data-bfrc-field' ) || key ) );
				} else if ( labels[ key ] ) {
					field.setAttribute( 'aria-label', rows.rowLabel( labels[ key ], index ) );
				}
			} );
		}

		function renumberAll( box ) {
			rowsOf( box ).forEach( function ( row, index ) {
				row.querySelectorAll( '[name]' ).forEach( function ( field ) {
					field.name = rows.renumber( field.name, index );
				} );
				relabel( box, row, index );
			} );
			checkFieldCount();
		}

		function showExtras( row ) {
			var select = row.querySelector( '.bfrc-rows__type' );
			if ( ! select ) {
				return;
			}
			row.querySelectorAll( '.bfrc-rows__extra' ).forEach( function ( field ) {
				var other = field.getAttribute( 'data-bfrc-type' ) !== select.value;
				field.hidden = other;
				field.disabled = other; // A disabled field is not posted; hidden alone still is.
			} );
		}

		function limitNote( box, text ) {
			var note = box.querySelector( '.bfrc-rows__limit' );
			if ( note ) {
				note.textContent = text;
			}
		}

		function countFields() {
			return doc.querySelectorAll( '.bfrc-editor [name]:not(:disabled)' ).length;
		}

		function checkFieldCount() {
			var warn = rows.tooManyFields( countFields(), maxInputVars );
			doc.querySelectorAll( '[data-bfrc-rows]' ).forEach( function ( box ) {
				var existing = box.querySelector( '.bfrc-fields-notice' );
				if ( ! warn ) {
					if ( existing ) {
						existing.remove();
					}
					return;
				}
				if ( ! existing ) {
					existing = doc.createElement( 'div' );
					existing.className = 'notice notice-warning inline bfrc-fields-notice';
					existing.setAttribute( 'role', 'alert' );
					box.insertBefore( existing, box.firstChild );
				}
				existing.textContent = i18n.tooManyFields || '';
			} );
		}

		function addRow( box ) {
			var max = parseInt( box.getAttribute( 'data-bfrc-max' ), 10 ) || maxRows;
			if ( ! rows.canAdd( rowsOf( box ).length, max ) ) {
				limitNote( box, i18n.maxRows || '' );
				speak( i18n.maxRows || '' );
				return;
			}
			limitNote( box, '' );
			var template = box.querySelector( '.bfrc-rows__template' );
			listOf( box ).appendChild( template.content.cloneNode( true ) );
			renumberAll( box );
			var added = rowsOf( box ).pop();
			showExtras( added );
			var first = added.querySelector( 'input:not([type=hidden]):not([hidden]), textarea' );
			if ( first ) {
				first.focus();
			}
		}

		function moveRow( box, row, delta ) {
			var all = rowsOf( box );
			var from = all.indexOf( row );
			var to = from + delta;
			if ( from < 0 || to < 0 || to >= all.length ) {
				return;
			}
			var list = listOf( box );
			list.insertBefore( row, delta < 0 ? all[ to ] : all[ to ].nextSibling );
			renumberAll( box );
			speak( rows.format( i18n.moved || '', to + 1 ) );
			var button = row.querySelector( '.bfrc-rows__' + ( delta < 0 ? 'up' : 'down' ) );
			if ( button ) {
				button.focus();
			}
		}

		doc.addEventListener( 'click', function ( event ) {
			var target = event.target;
			var box = container( target );
			if ( ! box || ! target.classList ) {
				return;
			}
			if ( target.classList.contains( 'bfrc-rows__add' ) ) {
				addRow( box );
			} else if ( target.classList.contains( 'bfrc-rows__remove' ) ) {
				target.closest( '.bfrc-rows__row' ).remove();
				renumberAll( box );
				limitNote( box, '' );
			} else if ( target.classList.contains( 'bfrc-rows__up' ) ) {
				moveRow( box, target.closest( '.bfrc-rows__row' ), -1 );
			} else if ( target.classList.contains( 'bfrc-rows__down' ) ) {
				moveRow( box, target.closest( '.bfrc-rows__row' ), 1 );
			}
		} );

		doc.addEventListener( 'change', function ( event ) {
			var target = event.target;
			if ( target && target.classList && target.classList.contains( 'bfrc-rows__type' ) ) {
				showExtras( target.closest( '.bfrc-rows__row' ) );
				checkFieldCount();
			}
		} );

		// Drag by the handle only: a draggable row would turn text selection in its fields into a drag.
		var dragging = null;
		var pending = false;
		doc.addEventListener( 'dragstart', function ( event ) {
			var handle = event.target && event.target.closest ? event.target.closest( '.bfrc-rows__handle' ) : null;
			if ( ! handle ) {
				return;
			}
			dragging = handle.closest( '.bfrc-rows__row' );
			if ( event.dataTransfer ) {
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData( 'text/plain', '' ); // Firefox needs data for a drag to start.
			}
		} );
		doc.addEventListener( 'dragover', function ( event ) {
			var row = event.target && event.target.closest ? event.target.closest( '.bfrc-rows__row' ) : null;
			if ( ! dragging || ! row || row === dragging || container( row ) !== container( dragging ) ) {
				return;
			}
			event.preventDefault();
			if ( pending ) {
				return;
			}
			pending = true;
			( win.requestAnimationFrame || function ( f ) { f(); } )( function () {
				pending = false;
				if ( ! dragging ) {
					return;
				}
				var all = rowsOf( container( row ) );
				var from = all.indexOf( dragging );
				var to = all.indexOf( row );
				if ( from < 0 || to < 0 ) {
					return;
				}
				listOf( container( row ) ).insertBefore( dragging, from < to ? row.nextSibling : row );
			} );
		} );
		doc.addEventListener( 'dragend', function () {
			if ( dragging ) {
				renumberAll( container( dragging ) );
				dragging = null;
			}
		} );

		// Classic editor: a constraint-invalid field inside a collapsed box would block Publish with no message. Open the box.
		doc.addEventListener( 'invalid', function ( event ) {
			var box = event.target && event.target.closest ? event.target.closest( '.postbox.closed' ) : null;
			if ( box ) {
				box.classList.remove( 'closed' );
			}
		}, true );

		doc.querySelectorAll( '.bfrc-rows__row' ).forEach( showExtras );
		doc.querySelectorAll( '[data-bfrc-rows]' ).forEach( function ( box ) {
			rowsOf( box ).forEach( function ( row, index ) {
				relabel( box, row, index );
			} );
		} );
		checkFieldCount();

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
				existing = doc.createElement( 'div' );
				existing.className = 'notice notice-warning inline bfrc-video-notice';
				existing.setAttribute( 'role', 'alert' );
				box.insertBefore( existing, field.closest( 'p' ).nextSibling );
			}
			existing.textContent = rows.format( i18n.videoRejected || '', field.value.trim() );
		}

		function checkVideo() {
			var field = doc.getElementById( 'bfrc-video-url' );
			if ( ! field ) {
				return Promise.resolve();
			}
			if ( field.value.trim() === '' ) {
				videoNotice( field, false );
				return Promise.resolve();
			}
			return win.wp.apiFetch( { path: config.restPath + '?context=edit&_fields=meta' } ).then( function ( post ) {
				var stored = post && post.meta ? post.meta[ config.videoKey ] : '';
				videoNotice( field, rows.videoRejected( field.value, stored ) );
			} ).catch( function () {
				// A failed read-back leaves whatever notice is showing; the next save tries again.
			} );
		}

		if ( config.restPath && win.wp && win.wp.data && win.wp.apiFetch ) {
			var wasSaving = false;
			win.wp.data.subscribe( function () {
				var store = win.wp.data.select( 'core/edit-post' );
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

		return { addRow: addRow, moveRow: moveRow, renumberAll: renumberAll, showExtras: showExtras, checkVideo: checkVideo, checkFieldCount: checkFieldCount, countFields: countFields };
	}

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = init;
	} else {
		root.bfrcRecipeEditorInit = init;
		init( root.document, root );
	}
}( typeof window !== 'undefined' ? window : this ) );
