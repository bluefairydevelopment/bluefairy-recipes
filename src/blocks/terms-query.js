/**
 * The REST query the inspector's fixed-filter dropdowns load terms with.
 * `per_page: -1` makes core-data fetch every page (the REST maximum is 100
 * a page), so a taxonomy with more terms than that lists them all.
 */
export const TERMS_QUERY = Object.freeze( { per_page: -1, orderby: 'name', order: 'asc', hide_empty: false } );
