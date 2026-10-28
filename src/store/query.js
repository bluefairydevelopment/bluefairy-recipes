/**
 * Pure helpers of the `bfrc/recipes` store: the request to the endpoint, the
 * page URL that mirrors it, and the grid and pagination markup (the same
 * markup Renderer::grid() and Blocks::page_links() print, around hrefs that
 * can differ from PHP's only in percent-encoding: URLSearchParams writes a
 * space as `+`, rawurlencode() as `%20`; the two pagination fixtures are
 * asserted equal by a test). No DOM, no runtime: tested with node --test.
 */

export const DEBOUNCE = 600;
export const SEARCH_MAX = 100;

export function escapeHtml( text ) {
	return String( text )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#039;' );
}

/** Keeps an href inside its attribute: `&` as `&#038;` like esc_url(); quotes and angle brackets percent-encoded (esc_url() strips them). */
export function escapeUrl( url ) {
	return String( url )
		.replace( /&/g, '&#038;' )
		.replace( /"/g, '%22' )
		.replace( /'/g, '%27' )
		.replace( /</g, '%3C' )
		.replace( />/g, '%3E' );
}

/** The non-empty filters, fixed ones winning over the form's. */
export function effectiveFilters( query, fixed ) {
	const out = {};
	Object.keys( query.filter || {} ).forEach( ( code ) => {
		if ( query.filter[ code ] ) {
			out[ code ] = String( query.filter[ code ] );
		}
	} );
	Object.keys( fixed || {} ).forEach( ( code ) => {
		if ( fixed[ code ] ) {
			out[ code ] = String( fixed[ code ] );
		}
	} );
	return out;
}

/** The endpoint's query string for the state: s, filter[code], page, per_page. */
export function buildParams( query, fixed, perPage, searchMax ) {
	const params = new URLSearchParams();
	const s = String( query.s || '' ).slice( 0, searchMax || SEARCH_MAX );
	if ( s !== '' ) {
		params.set( 's', s );
	}
	const filters = effectiveFilters( query, fixed );
	Object.keys( filters ).forEach( ( code ) => {
		params.set( 'filter[' + code + ']', filters[ code ] );
	} );
	params.set( 'page', String( Math.max( 1, Number( query.page ) || 1 ) ) );
	params.set( 'per_page', String( Math.max( 1, Math.min( 100, Number( perPage ) || 1 ) ) ) );
	return params.toString();
}

/**
 * The page's own URL for the state (what a reload or a shared link would
 * open): bfrc_s, bfrc_filter[code] for the user's filters (never a fixed
 * code: the page applies those itself, and a choice for one is ignored) and
 * bfrc_page above 1.
 *
 * @param {string} base      The page URL (state.baseUrl).
 * @param {Object} query     { s, filter, page }.
 * @param {Object} vars      The query-var names from the config ({ s, filter, page }).
 * @param {Object} [fixed]   The list's fixed filters, code => slug.
 * @param {number} [searchMax] Keyword length bound (config.searchMax).
 * @return {string} The URL.
 */
export function buildPageUrl( base, query, vars, fixed, searchMax ) {
	const url = new URL( base, 'https://localhost/' );
	[ vars.s, vars.page ].forEach( ( name ) => url.searchParams.delete( name ) );
	Array.from( url.searchParams.keys() ).forEach( ( key ) => {
		if ( key.indexOf( vars.filter + '[' ) === 0 ) {
			url.searchParams.delete( key );
		}
	} );
	const s = String( query.s || '' ).slice( 0, searchMax || SEARCH_MAX );
	if ( s !== '' ) {
		url.searchParams.set( vars.s, s );
	}
	Object.keys( query.filter || {} ).forEach( ( code ) => {
		if ( query.filter[ code ] && ! ( fixed && fixed[ code ] ) ) {
			url.searchParams.set( vars.filter + '[' + code + ']', String( query.filter[ code ] ) );
		}
	} );
	const page = Math.max( 1, Number( query.page ) || 1 );
	if ( page > 1 ) {
		url.searchParams.set( vars.page, String( page ) );
	}
	// A base that was relative stays relative.
	return base.indexOf( 'https://localhost/' ) === 0 || /^[a-z]+:/i.test( base ) ? url.toString() : url.pathname + url.search;
}

/** As Blocks::page_items(): end_size 1, mid_size 2, 'dots' for each gap. */
export function pageItems( current, total ) {
	const items = [];
	let dots = false;
	for ( let n = 1; n <= total; n++ ) {
		if ( n === 1 || n === total || ( n >= current - 2 && n <= current + 2 ) ) {
			items.push( n );
			dots = true;
		} else if ( dots ) {
			items.push( 'dots' );
			dots = false;
		}
	}
	return items;
}

/** As Renderer::grid(): the card list, or the "no recipes" line. */
export function gridHtml( cards, emptyText ) {
	if ( ! cards || cards.length === 0 ) {
		return '<p class="bfrc-empty">' + escapeHtml( emptyText ) + '</p>';
	}
	return '<ul class="bfrc-grid">' + cards.map( ( card ) => '<li class="bfrc-grid__item">' + card + '</li>' ).join( '' ) + '</ul>';
}

/**
 * As Blocks::page_links(): ul.page-numbers with prev, numbers, dots and
 * next; '' for one page. `urlFor( n )` gives the page's plain href; the
 * labels arrive escaped from the server config.
 */
export function paginationHtml( current, total, urlFor, labels ) {
	if ( total < 2 ) {
		return '';
	}
	const link = ( page, className, text ) =>
		'<li><a class="' + className + '" href="' + escapeUrl( urlFor( page ) ) + '" data-bfrc-page="' + page + '">' + text + '</a></li>';
	let html = '<ul class="page-numbers">';
	if ( current > 1 ) {
		html += link( current - 1, 'prev page-numbers', labels.prev );
	}
	pageItems( current, total ).forEach( ( item ) => {
		if ( item === 'dots' ) {
			html += '<li><span class="page-numbers dots">&hellip;</span></li>';
		} else if ( item === current ) {
			html += '<li><span aria-current="page" class="page-numbers current" tabindex="-1">' + item + '</span></li>';
		} else {
			html += link( item, 'page-numbers', String( item ) );
		}
	} );
	if ( current < total ) {
		html += link( current + 1, 'next page-numbers', labels.next );
	}
	return html + '</ul>';
}

/** The server's "N recipes shown." for the count (each made with _n(), so every plural form is right); the bare number if the list has none for it. */
export function statusText( texts, count ) {
	return Array.isArray( texts ) && typeof texts[ count ] === 'string' ? texts[ count ] : String( count );
}

/** Is anything chosen in the form? */
export function isFiltered( query ) {
	if ( query.s ) {
		return true;
	}
	return Object.keys( query.filter || {} ).some( ( code ) => !! query.filter[ code ] );
}
