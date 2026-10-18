/**
 * Image pickers on the writer profile: open the media library (one frame per
 * picker, reused), keep the chosen attachment id in the hidden field and show
 * a preview. The pure part (what to write for a selection) is exported for
 * `node --test`.
 *
 * @package BlueFairy\Recipes
 */
( function ( root ) {
	'use strict';

	/**
	 * What a selection means for the hidden field and the preview.
	 *
	 * @param {Object|null} attachment The chosen attachment (`id`, `sizes`, `url`) or null to clear.
	 * @return {{id: string, src: string}} The id to store and the preview source.
	 */
	function selection( attachment ) {
		if ( ! attachment || ! ( attachment.id > 0 ) ) {
			return { id: '0', src: '' };
		}
		var sizes = attachment.sizes || {};
		var src = ( sizes.thumbnail && sizes.thumbnail.url ) || attachment.url || '';
		return { id: String( attachment.id ), src: src };
	}

	/**
	 * Write a selection into a picker's DOM.
	 *
	 * @param {Element} picker The `.bfrc-image-picker` cell.
	 * @param {{id: string, src: string}} state From `selection()`.
	 * @param {Document} doc The document (for tests).
	 */
	function apply( picker, state, doc ) {
		doc = doc || document;
		picker.querySelector( '.bfrc-image-picker__id' ).value = state.id;
		var preview = picker.querySelector( '.bfrc-image-picker__preview' );
		while ( preview.firstChild ) {
			preview.removeChild( preview.firstChild );
		}
		if ( state.src ) {
			var img = doc.createElement( 'img' );
			img.src = state.src;
			img.width = 96;
			img.height = 96;
			img.alt = '';
			preview.appendChild( img );
		}
		picker.querySelector( '.bfrc-image-picker__remove' ).hidden = ! state.src;
	}

	var api = { selection: selection, apply: apply };
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
		return;
	}
	root.bfrcWriterProfileRows = api;

	var config = root.bfrcWriterProfile || { i18n: {} };
	var frames = {};
	document.addEventListener( 'click', function ( event ) {
		var picker = event.target.closest && event.target.closest( '.bfrc-image-picker' );
		if ( ! picker || ! root.wp || ! root.wp.media ) {
			return;
		}
		if ( event.target.classList.contains( 'bfrc-image-picker__remove' ) ) {
			apply( picker, selection( null ) );
			return;
		}
		if ( ! event.target.classList.contains( 'bfrc-image-picker__choose' ) ) {
			return;
		}
		var key = picker.getAttribute( 'data-bfrc-picker' ) || 'default';
		if ( ! frames[ key ] ) {
			frames[ key ] = root.wp.media( {
				title: config.i18n.choose || '',
				library: { type: 'image' },
				multiple: false,
				button: { text: config.i18n.use || '' }
			} );
			frames[ key ].on( 'select', function () {
				var chosen = frames[ key ].state().get( 'selection' ).first();
				apply( picker, selection( chosen ? chosen.toJSON() : null ) );
			} );
		}
		frames[ key ].open();
	} );
}( typeof window !== 'undefined' ? window : this ) );
