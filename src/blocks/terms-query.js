/**
 * The REST query the inspector's fixed-filter dropdowns load terms with.
 * `per_page: -1` makes core-data fetch every page (the REST maximum is 100
 * a page), so a taxonomy with more terms than that lists them all.
 */
export const TERMS_QUERY = Object.freeze( { per_page: -1, orderby: 'name', order: 'asc', hide_empty: false } );

/**
 * Whether the inspector offers a taxonomy as a fixed filter: one of the
 * plugin's (`bfrc_` prefix) and enabled. `enabled` is the code list the
 * server prints (window.bfrcEnabledFilters); without it every plugin type is
 * offered, as before.
 *
 * @param {string}             slug    The taxonomy name.
 * @param {string[]|undefined} enabled The enabled codes.
 * @return {boolean} Offered or not.
 */
export function isOfferedFilter( slug, enabled ) {
	if ( slug.indexOf( 'bfrc_' ) !== 0 ) {
		return false;
	}
	return ! Array.isArray( enabled ) || enabled.indexOf( slug.slice( 5 ) ) !== -1;
}
