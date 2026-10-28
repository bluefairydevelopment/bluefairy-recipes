// The bfrc/recipes store's actions and callbacks, run against a fake runtime: a fake state, element, config, fetch, timers, history and abort controllers.
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );

const CONFIG = {
	vars: { s: 'bfrc_s', filter: 'bfrc_filter', page: 'bfrc_page' },
	searchMax: 100,
	emptyText: 'No recipes match. Try fewer filters.',
	loadingText: 'Loading recipes…',
	errorText: 'The recipes could not be loaded. Press Filter to reload the page.',
	foundTexts: [ '0 recipes shown.', '1 recipe shown.', '2 recipes shown.', '3 recipes shown.', '4 recipes shown.' ],
	prevText: '&laquo; Previous',
	nextText: 'Next &raquo;',
	debounce: 600,
};

// What wp_interactivity_state() sends.
const SERVER_STATE = () => ( { restUrl: 'https://example.com/wp-json/bfrc/v1/recipes', baseUrl: 'https://example.com/cook/', perPage: 4, query: { s: '', filter: {}, page: 1 }, fixed: {}, total: 0, totalPages: 0, hasPages: false, filtered: false, loading: false, error: false, cards: null, status: '', version: 0 } );

function response( items, total, pages, ok = true ) {
	return {
		ok,
		status: ok ? 200 : 500,
		headers: { get: ( name ) => ( { 'X-WP-Total': String( total ), 'X-WP-TotalPages': String( pages ) }[ name ] ) },
		json: async () => items,
	};
}

class FakeAbort {
	constructor() { this.aborted = false; this.signal = this; }
	abort() { this.aborted = true; }
}

async function harness( overrides ) {
	const { createStore } = await import( '../../src/store/definition.js' );
	const calls = { fetch: [], replaced: [], timers: [], controllers: [] };
	const results = { innerHTML: '' };
	const pagination = { innerHTML: '' };
	let element = results;
	const api = {
		state: () => def.state,
		getElement: () => ( { ref: element } ),
		getConfig: () => CONFIG,
		fetch: async ( url, options ) => { calls.fetch.push( url ); const respond = ( overrides && overrides.respond ) ? overrides.respond : () => response( [], 0, 0 ); return respond( url, options ); },
		setTimeout: ( fn, ms ) => { calls.timers.push( ms ); const id = { fn }; api.lastTimer = id; return id; }, // Timers are held; the test fires them.
		clearTimeout: ( id ) => { if ( id ) { id.cleared = true; } },
		history: { replaceState: ( a, b, url ) => calls.replaced.push( url ) },
		abortController: () => { const c = new FakeAbort(); calls.controllers.push( c ); return c; },
		hasList: () => ( overrides && overrides.hasList === false ? false : true ),
	};
	const def = createStore( api );
	assert.deepEqual( Object.keys( def.state ), [], 'the definition sets no state value: store() would merge it over the server\'s' );
	Object.assign( def.state, ( overrides && overrides.state ) || SERVER_STATE() );
	return { def, state: def.state, actions: def.actions, callbacks: def.callbacks, calls, results, pagination, setElement: ( el ) => { element = el; }, api };
}

test( 'typing schedules one debounced load after the last keystroke, resets the page, and ignores composition', async () => {
	const h = await harness( { respond: () => response( [ { html: '<article>a</article>' } ], 9, 3 ) } );
	h.state.query.page = 3;
	h.actions.keyword( { isComposing: true, target: { value: 'p' } } );
	assert.equal( h.calls.timers.length, 0, 'mid-composition: nothing' );
	h.actions.keyword( { isComposing: false, target: { value: 'pi' } } );
	h.actions.keyword( { isComposing: false, target: { value: 'pie' } } );
	assert.deepEqual( h.calls.timers, [ 600, 600 ], 'each keystroke re-arms the 600 ms timer' );
	assert.equal( h.state.query.s, 'pie' );
	assert.equal( h.state.query.page, 1, 'a new keyword starts at page 1' );
	assert.equal( h.calls.fetch.length, 0, 'nothing fetched before the timer fires' );
	await h.api.lastTimer.fn();
	assert.deepEqual( h.calls.fetch, [ 'https://example.com/wp-json/bfrc/v1/recipes?s=pie&page=1&per_page=4' ] );
	assert.deepEqual( h.state.cards, [ '<article>a</article>' ] );
	assert.deepEqual( [ h.state.total, h.state.totalPages, h.state.hasPages, h.state.filtered, h.state.loading, h.state.error, h.state.version ], [ 9, 3, true, true, false, false, 1 ] );
	assert.equal( h.state.status, '1 recipe shown.', 'singular' );
	assert.deepEqual( h.calls.replaced, [ 'https://example.com/cook/?bfrc_s=pie' ], 'the page URL follows' );
	h.actions.keyword( { isComposing: false, target: { value: 'x'.repeat( 150 ) } } );
	assert.equal( h.state.query.s.length, 100, 'cut at the server\'s maximum' );
} );

test( 'a select change, the Filter button, Reset and a page link, with focus placed afterwards', async () => {
	const h = await harness( { respond: ( url ) => response( [ { html: '<article>' + url + '</article>' }, { html: '<article>b</article>' } ], 5, 2 ) } );
	h.state.fixed = { meal_type: 'dinner' };
	h.actions.filter( { target: { getAttribute: () => 'diet', value: 'vegan' } } );
	assert.equal( h.calls.timers.length, 1 );
	h.actions.filter( { target: { getAttribute: () => null, value: 'x' } } );
	assert.equal( h.calls.timers.length, 1, 'a select without a code is ignored' );
	h.actions.filter( { target: { getAttribute: () => 'meal_type', value: 'lunch' } } );
	assert.equal( h.calls.timers.length, 1, 'a change on a fixed code\'s (hidden) select is ignored too' );
	assert.equal( h.state.query.filter.meal_type, undefined );
	let prevented = 0;
	await h.actions.submit( { preventDefault: () => prevented++ } );
	assert.equal( prevented, 1, 'the Filter button loads at once, no navigation' );
	assert.ok( h.api.lastTimer.cleared, 'and cancels the pending timer' );
	assert.equal( h.calls.fetch[ 0 ], 'https://example.com/wp-json/bfrc/v1/recipes?filter%5Bdiet%5D=vegan&filter%5Bmeal_type%5D=dinner&page=1&per_page=4', 'fixed filters are sent too' );
	assert.equal( h.calls.replaced[ 0 ], 'https://example.com/cook/?bfrc_filter%5Bdiet%5D=vegan', 'but never put in the page URL' );
	assert.equal( h.state.filtered, true );
	assert.equal( h.state.status, '2 recipes shown.', 'plural' );
	// A page link: delegated from the nav; the list is scrolled to only when its top is above the viewport; focus goes to the new current page without scrolling.
	let scrolled = 0;
	let focused = null;
	const current = { focus: ( opts ) => { focused = 'current:' + JSON.stringify( opts ); } };
	const list = { scrollIntoView: () => scrolled++, getBoundingClientRect: () => ( { top: 120 } ) };
	const link = { getAttribute: () => '2', closest: ( sel ) => ( sel === '.bfrc-list' ? list : null ) };
	await h.actions.paginate( { preventDefault: () => prevented++, target: { closest: ( sel ) => ( sel === 'a[data-bfrc-page]' ? link : null ) } } );
	assert.equal( h.state.query.page, 2 );
	assert.equal( scrolled, 0, 'the list top is in view: no scroll' );
	assert.equal( focused, null, 'not yet: the nav is about to be rebuilt' );
	h.pagination.querySelector = ( sel ) => ( sel === '[aria-current="page"]' ? current : null );
	h.pagination.closest = ( sel ) => ( sel === '.bfrc-list' ? list : null );
	h.setElement( h.pagination );
	h.callbacks.renderPagination();
	assert.equal( focused, 'current:{"preventScroll":true}', 'focus lands on the new current page once the nav is rebuilt, without scrolling the nav into view' );
	focused = null;
	const written = h.pagination.innerHTML;
	h.pagination.innerHTML = 'untouched';
	h.callbacks.renderPagination();
	assert.equal( h.pagination.innerHTML, 'untouched', 'the watcher re-running for the same answer writes nothing (the DOM write that would destroy the focused span)' );
	assert.equal( focused, null, 'and focus is placed once' );
	h.pagination.innerHTML = written;
	assert.ok( h.calls.fetch[ 1 ].endsWith( '&page=2&per_page=4' ) );
	assert.equal( h.calls.replaced[ 1 ], 'https://example.com/cook/?bfrc_filter%5Bdiet%5D=vegan&bfrc_page=2' );
	list.getBoundingClientRect = () => ( { top: -40 } );
	await h.actions.paginate( { preventDefault: () => prevented++, target: { closest: ( sel ) => ( sel === 'a[data-bfrc-page]' ? link : null ) } } );
	assert.equal( scrolled, 1, 'the list top is above the viewport: scrolled into view before the fetch' );
	h.callbacks.renderPagination();
	assert.equal( scrolled, 2, 'and again after the new cards are in, when the list top is still above the viewport (focus must not scroll to the nav instead)' );
	await h.actions.paginate( { preventDefault: () => prevented++, target: { closest: () => null } } );
	assert.equal( prevented, 3, 'a click elsewhere in the nav is not a page' );
	// Reset: fields cleared, state cleared, loaded, focus on the search box.
	const fields = [ { value: 'vegan' }, { value: 'pie' } ];
	const search = { focus: () => { focused = 'search'; } };
	await h.actions.reset( { preventDefault: () => prevented++, target: { closest: () => ( { querySelectorAll: () => fields, querySelector: () => search } ) } } );
	assert.deepEqual( fields.map( ( f ) => f.value ), [ '', '' ] );
	assert.deepEqual( [ h.state.query.s, h.state.query.filter, h.state.query.page ], [ '', {}, 1 ] );
	assert.equal( h.calls.replaced[ 3 ], 'https://example.com/cook/' );
	assert.equal( h.state.filtered, false );
	assert.equal( focused, 'search' );
} );

test( 'callbacks write the grid and the pagination only after a load, as the PHP markup', async () => {
	const h = await harness( { respond: () => response( [ { html: '<article>a</article>' }, { html: '<article>b</article>' } ], 9, 3 ) } );
	h.callbacks.renderResults();
	h.setElement( h.pagination );
	h.callbacks.renderPagination();
	assert.equal( h.results.innerHTML, '', 'before any load the server markup stays' );
	assert.equal( h.pagination.innerHTML, '' );
	h.state.query = { s: 'pie', filter: {}, page: 2 };
	await h.actions.submit( { preventDefault() {} } );
	h.setElement( h.results );
	h.callbacks.renderResults();
	assert.equal( h.results.innerHTML, '<ul class="bfrc-grid"><li class="bfrc-grid__item"><article>a</article></li><li class="bfrc-grid__item"><article>b</article></li></ul>' );
	h.results.innerHTML = 'untouched';
	h.callbacks.renderResults();
	assert.equal( h.results.innerHTML, 'untouched', 'the results watcher re-running for the same answer writes nothing' );
	h.setElement( h.pagination );
	h.callbacks.renderPagination();
	assert.equal( h.pagination.innerHTML, '<ul class="page-numbers"><li><a class="prev page-numbers" href="https://example.com/cook/?bfrc_s=pie" data-bfrc-page="1">&laquo; Previous</a></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=pie" data-bfrc-page="1">1</a></li><li><span aria-current="page" class="page-numbers current" tabindex="-1">2</span></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=pie&#038;bfrc_page=3" data-bfrc-page="3">3</a></li><li><a class="next page-numbers" href="https://example.com/cook/?bfrc_s=pie&#038;bfrc_page=3" data-bfrc-page="3">Next &raquo;</a></li></ul>' );
	const empty = await harness( { respond: () => response( [], 0, 0 ) } );
	await empty.actions.submit( { preventDefault() {} } );
	empty.callbacks.renderResults();
	assert.equal( empty.results.innerHTML, '<p class="bfrc-empty">No recipes match. Try fewer filters.</p>' );
	assert.equal( empty.state.hasPages, false );
	assert.equal( empty.state.status, '0 recipes shown.' );
} );

test( 'a page request a keystroke supersedes never moves focus later', async () => {
	let resolveSlow;
	const slow = new Promise( ( r ) => { resolveSlow = r; } );
	const h = await harness( { respond: ( url ) => ( url.includes( 'page=2' ) ? slow : response( [ { html: '<article>pie</article>' } ], 1, 1 ) ) } );
	let focused = 0;
	h.pagination.querySelector = () => ( { focus: () => focused++ } );
	const link = { getAttribute: () => '2', closest: () => null };
	const p2 = h.actions.paginate( { preventDefault() {}, target: { closest: () => link } } );
	h.actions.keyword( { isComposing: false, target: { value: 'pie' } } );
	await h.api.lastTimer.fn();
	resolveSlow( response( [], 0, 0, false ) );
	await p2;
	h.setElement( h.pagination );
	h.callbacks.renderPagination();
	assert.equal( focused, 0, 'the keyword answer is rendered without stealing focus from the search box' );
	const failed = await harness( { respond: () => response( [], 0, 0, false ) } );
	await failed.actions.paginate( { preventDefault() {}, target: { closest: () => link } } );
	assert.equal( failed.state.error, true );
	failed.state.error = false; // The next keystroke's load (keyword ignores the error flag by design).
	failed.actions.keyword( { isComposing: false, target: { value: 'x' } } );
	failed.api.fetch = async () => response( [ { html: '<article>x</article>' } ], 1, 1 );
	await failed.api.lastTimer.fn();
	let focusedAfterFailure = 0;
	failed.pagination.querySelector = () => ( { focus: () => focusedAfterFailure++ } );
	failed.setElement( failed.pagination );
	failed.callbacks.renderPagination();
	assert.equal( focusedAfterFailure, 0, 'a failed page request leaves no focus request behind' );
} );

test( 'a late response for an earlier request is dropped and the request is aborted', async () => {
	let resolveSlow;
	const slow = new Promise( ( r ) => { resolveSlow = r; } );
	const h = await harness( { respond: ( url ) => ( url.includes( 'page=2' ) ? slow : response( [ { html: '<article>pie</article>' } ], 1, 1 ) ) } );
	const link = { getAttribute: () => '2', closest: () => null };
	const p2 = h.actions.paginate( { preventDefault() {}, target: { closest: () => link } } ); // page 2: slow
	h.actions.keyword( { isComposing: false, target: { value: 'pie' } } ); // then a keyword
	const p1 = h.api.lastTimer.fn();
	assert.equal( h.calls.controllers.length, 2 );
	assert.equal( h.calls.controllers[ 0 ].aborted, true, 'the page-2 request was aborted when the keyword request went out' );
	resolveSlow( response( [ { html: '<article>page 2 stale</article>' }, { html: '<article>x</article>' } ], 5, 2 ) ); // the stale answer arrives last
	await p1;
	await p2;
	assert.deepEqual( h.state.cards, [ '<article>pie</article>' ], 'the newer request\'s answer stays' );
	assert.deepEqual( [ h.state.query.page, h.state.totalPages, h.state.loading, h.state.version ], [ 1, 1, false, 1 ] );
	assert.deepEqual( h.calls.replaced, [ 'https://example.com/cook/?bfrc_s=pie' ], 'the URL was written once, for the kept answer' );
} );

test( 'a failed or malformed response leaves the page as it was and hands the form back to the browser', async () => {
	const h = await harness( { respond: () => response( [], 0, 0, false ) } );
	await h.actions.submit( { preventDefault() {} } );
	assert.deepEqual( [ h.state.error, h.state.loading, h.state.cards, h.state.version ], [ true, false, null, 0 ] );
	assert.equal( h.state.status, CONFIG.errorText );
	assert.equal( h.calls.replaced.length, 0, 'the URL is not changed for a load that failed' );
	let prevented = 0;
	await h.actions.submit( { preventDefault: () => prevented++ } );
	assert.equal( prevented, 0, 'after an error the Filter button submits the plain form' );
	await h.actions.paginate( { preventDefault: () => prevented++, target: { closest: () => ( { getAttribute: () => '2', closest: () => null } ) } } );
	assert.equal( prevented, 0, 'and page links navigate' );
	const bad = await harness( { respond: () => ( { ok: true, status: 200, headers: { get: () => '0' }, json: async () => ( { not: 'a list' } ) } ) } );
	await bad.actions.submit( { preventDefault() {} } );
	assert.equal( bad.state.error, true, 'a non-list body is an error, never rendered' );
} );

test( 'a Filters block with no live list on the page is left to the browser', async () => {
	const h = await harness( { hasList: false } );
	let prevented = 0;
	h.actions.keyword( { isComposing: false, target: { value: 'pie' } } );
	await h.actions.submit( { preventDefault: () => prevented++ } );
	assert.equal( prevented, 0, 'the plain GET form posts to the page' );
	assert.equal( h.calls.fetch.length, 0 );
	assert.equal( h.calls.timers.length, 0 );
} );

test( 'without a store (no list seeded it) the form is left to the browser and nothing throws', async () => {
	const h = await harness( { state: {} } );
	let prevented = 0;
	h.actions.keyword( { isComposing: false, target: { value: 'pie' } } );
	h.actions.filter( { target: { getAttribute: () => 'diet', value: 'vegan' } } );
	await h.actions.submit( { preventDefault: () => prevented++ } );
	await h.actions.reset( { preventDefault: () => prevented++, target: { closest: () => null } } );
	await h.actions.paginate( { preventDefault: () => prevented++, target: { closest: () => ( { getAttribute: () => '2', closest: () => null } ) } } );
	assert.equal( prevented, 0 );
	assert.equal( h.calls.fetch.length, 0 );
	assert.equal( h.calls.timers.length, 0 );
} );
