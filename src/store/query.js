/**
 * Pure helpers of the `bfrc/recipes` store: the request to the endpoint, the
 * page URL that mirrors it, and the grid and pagination markup, built to the
 * byte as the PHP side builds them (Renderer::grid(), Blocks::page_links()).
 * No DOM, no runtime: tested with node --test.
 */

export const DEBOUNCE = 600;
export const SEARCH_MAX = 100;

function escapeHtml( text ) {
	return String( text )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#039;' );
}

/** esc_url() of an href: `&` becomes `&#038;`. */
function escapeUrl( url ) {
	return String( url ).replace( /&/g, '&#038;' );
}

/** The non-empty filters, fixed ones first (a fixed code wins). */
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
export function buildParams( query, fixed, perPage ) {
	const params = new URLSearchParams();
	const s = String( query.s || '' ).slice( 0, SEARCH_MAX );
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
 * open): bfrc_s, bfrc_filter[code] for the user's filters (never the fixed
 * ones: the page applies those itself) and bfrc_page above 1.
 */
export function buildPageUrl( base, query, vars ) {
	const url = new URL( base, 'https://localhost/' );
	[ vars.s, vars.page ].forEach( ( name ) => url.searchParams.delete( name ) );
	Array.from( url.searchParams.keys() ).forEach( ( key ) => {
		if ( key.indexOf( vars.filter + '[' ) === 0 ) {
			url.searchParams.delete( key );
		}
	} );
	const s = String( query.s || '' ).slice( 0, SEARCH_MAX );
	if ( s !== '' ) {
		url.searchParams.set( vars.s, s );
	}
	Object.keys( query.filter || {} ).forEach( ( code ) => {
		if ( query.filter[ code ] ) {
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
 * next; '' for one page. `urlFor( n )` gives the page's plain href.
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
			html += '<li><span aria-current="page" class="page-numbers current">' + item + '</span></li>';
		} else {
			html += link( item, 'page-numbers', String( item ) );
		}
	} );
	if ( current < total ) {
		html += link( current + 1, 'next page-numbers', labels.next );
	}
	return html + '</ul>';
}

/** "%s recipes shown." with the count. */
export function statusText( template, count ) {
	return String( template ).replace( '%s', String( count ) );
}
