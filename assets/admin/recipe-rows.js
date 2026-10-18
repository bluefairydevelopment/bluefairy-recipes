/**
 * Repeatable-row logic for the recipe editor, kept free of the DOM so it can
 * be tested with Node alone: given the rows of a list and an action, return
 * the new order; given a field name and an index, return the renumbered name.
 *
 * @package BlueFairy\Recipes
 */
( function ( root ) {
	'use strict';

	/**
	 * `bfrc[ingredients][3][label]` with index 7 → `bfrc[ingredients][7][label]`.
	 * The template uses `__INDEX__` in that position.
	 *
	 * @param {string} name  A field name.
	 * @param {number} index The new row index.
	 * @return {string} The renumbered name.
	 */
	function renumber( name, index ) {
		return String( name ).replace( /\[(?:\d+|__INDEX__)\]/, '[' + index + ']' );
	}

	/**
	 * Move the item at `from` to `to` in a copy of `items`; out-of-range
	 * positions leave the order unchanged.
	 *
	 * @param {Array} items The rows.
	 * @param {number} from  Current index.
	 * @param {number} to    Target index.
	 * @return {Array} A new array.
	 */
	function move( items, from, to ) {
		var copy = items.slice();
		if ( from < 0 || from >= copy.length || to < 0 || to >= copy.length || from === to ) {
			return copy;
		}
		var item = copy.splice( from, 1 )[ 0 ];
		copy.splice( to, 0, item );
		return copy;
	}

	/**
	 * May another row be added?
	 *
	 * @param {number} count Current row count.
	 * @param {number} max   The ceiling.
	 * @return {boolean} Whether one more fits.
	 */
	function canAdd( count, max ) {
		return count < max;
	}

	/**
	 * After a save: was a typed video URL refused by the server? True when the
	 * writer typed something and the stored value came back empty.
	 *
	 * @param {string} typed  The field's value.
	 * @param {string} stored The value the server kept.
	 * @return {boolean} Whether to warn.
	 */
	function videoRejected( typed, stored ) {
		return String( typed || '' ).trim() !== '' && String( stored || '' ).trim() === '';
	}

	/**
	 * Fill a label template: `%d` or `%1$d` with the 1-based row number,
	 * `%2$s` or `%s` with a field label. Replacement is literal (no `$&`).
	 *
	 * @param {string} template The translated template.
	 * @param {number} index    0-based row index.
	 * @param {string} [extra]  A field label.
	 * @return {string} The label.
	 */
	function rowLabel( template, index, extra ) {
		var n = String( index + 1 );
		return String( template || '' )
			.replace( /%1\$d|%d/g, function () { return n; } )
			.replace( /%2\$s|%s/g, function () { return String( extra || '' ); } );
	}

	/**
	 * Literal `%s` / `%d` substitution for a single value.
	 *
	 * @param {string} template The translated template.
	 * @param {string|number} value The value.
	 * @return {string} The text.
	 */
	function format( template, value ) {
		return String( template || '' ).replace( /%[sd]/, function () { return String( value ); } );
	}

	/**
	 * Will this many posted fields, plus a margin for the rest of the form,
	 * exceed PHP's ceiling? An unknown ceiling (0) never warns.
	 *
	 * @param {number} fields   Named, enabled controls in our boxes.
	 * @param {number} max      PHP max_input_vars (0 = unknown).
	 * @param {number} [margin] Fields core posts outside our boxes (default 120).
	 * @return {boolean} Whether to warn.
	 */
	function tooManyFields( fields, max, margin ) {
		if ( ! ( max > 0 ) ) {
			return false;
		}
		return fields + ( margin === undefined ? 120 : margin ) > max;
	}

	var api = { renumber: renumber, move: move, canAdd: canAdd, videoRejected: videoRejected, rowLabel: rowLabel, format: format, tooManyFields: tooManyFields };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	} else {
		root.bfrcRecipeRows = api;
	}
}( typeof window !== 'undefined' ? window : this ) );
