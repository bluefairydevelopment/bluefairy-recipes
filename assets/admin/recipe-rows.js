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

	var api = { renumber: renumber, move: move, canAdd: canAdd };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	} else {
		root.bfrcRecipeRows = api;
	}
}( typeof window !== 'undefined' ? window : this ) );
