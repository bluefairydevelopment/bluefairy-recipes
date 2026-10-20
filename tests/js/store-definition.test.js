// The bfrc/recipes store's actions and callbacks, run against a fake runtime: a fake state, element, config, fetch and history.
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );

const CONFIG = {
	vars: { s: 'bfrc_s', filter: 'bfrc_filter', page: 'bfrc_page' },
	emptyText: 'No recipes match. Try fewer filters.',
	loadingText: 'Loading recipes…',
	errorText: 'The recipes could not be loaded. Press Filter to reload the page.',
	foundText: '%s recipes shown.',
	prevText: '&laquo; Previous',
	nextText: 'Next &raquo;',
	debounce: 600,
};

function response( items, total, pages, ok = true ) {
	return {
		ok,
		status: ok ? 200 : 500,
		headers: { get: ( name ) => ( { 'X-WP-Total': String( total ), 'X-WP-TotalPages': String( pages ) }[ name ] ) },
		json: async () => items,
	};
}

async function harness( overrides ) {
	const { createStore } = await import( '../../src/store/definition.js' );
	const calls = { fetch: [], replaced: [], timers: [] };
	const results = { innerHTML: '' };
	const pagination = { innerHTML: '' };
	let element = results;
	const api = {
		state: () => def.state,
		getElement: () => ( { ref: element } ),
		getConfig: () => CONFIG,
		fetch: async ( url ) => { calls.fetch.push( url ); return ( overrides && overrides.respond ? overrides.respond : () => response( [], 0, 0 ) )( url ); },
		setTimeout: ( fn, ms ) => { calls.timers.push( ms ); return { fn }; }, // Timers are held; the test fires them.
		clearTimeout: ( id ) => { if ( id ) { id.cleared = true; } },
		history: { replaceState: ( a, b, url ) => calls.replaced.push( url ) },
	};
	const def = createStore( api );
	// What wp_interactivity_state() sends; the definition itself carries no values (store() would merge them over the server's).
	assert.deepEqual( Object.keys( def.state ), [ 'filtered' ], 'the definition defines only the derived getter' );
	Object.assign( def.state, { restUrl: 'https://example.com/wp-json/bfrc/v1/recipes', baseUrl: 'https://example.com/cook/', perPage: 4, query: { s: '', filter: {}, page: 1 }, fixed: {}, total: 0, totalPages: 0, hasPages: false, loading: false, error: false, cards: null, status: '', version: 0 } );
	return { def, state: def.state, actions: def.actions, callbacks: def.callbacks, calls, results, pagination, setElement: ( el ) => { element = el; }, fire: () => { const t = calls.timers.length; const last = api.lastTimer; return last; }, api };
}

// Reach the held timer: setTimeout returned { fn }; keep the latest.
function patchTimers( h ) {
	const original = h.api.setTimeout;
	h.api.setTimeout = ( fn, ms ) => { const id = original( fn, ms ); h.api.lastTimer = id; return id; };
}

test( 'typing schedules one debounced load after the last keystroke, resets the page, and ignores composition', async () => {
	const h = await harness( { respond: () => response( [ { html: '<article>a</article>' } ], 9, 3 ) } );
	patchTimers( h );
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
	assert.deepEqual( [ h.state.total, h.state.totalPages, h.state.hasPages, h.state.loading, h.state.error, h.state.version ], [ 9, 3, true, false, false, 1 ] );
	assert.equal( h.state.status, '1 recipes shown.' );
	assert.deepEqual( h.calls.replaced, [ 'https://example.com/cook/?bfrc_s=pie' ], 'the page URL follows' );
} );

test( 'a select change, the Filter button, Reset and a page link', async () => {
	const h = await harness( { respond: ( url ) => response( [ { html: '<article>' + url + '</article>' } ], 5, 2 ) } );
	patchTimers( h );
	h.state.fixed = { meal_type: 'dinner' };
	h.actions.filter( { target: { getAttribute: () => 'diet', value: 'vegan' } } );
	assert.equal( h.calls.timers.length, 1 );
	h.actions.filter( { target: { getAttribute: () => null, value: 'x' } } );
	assert.equal( h.calls.timers.length, 1, 'a select without a code is ignored' );
	let prevented = 0;
	await h.actions.submit( { preventDefault: () => prevented++ } );
	assert.equal( prevented, 1, 'the Filter button loads at once, no navigation' );
	assert.ok( h.api.lastTimer.cleared, 'and cancels the pending timer' );
	assert.equal( h.calls.fetch[ 0 ], 'https://example.com/wp-json/bfrc/v1/recipes?filter%5Bdiet%5D=vegan&filter%5Bmeal_type%5D=dinner&page=1&per_page=4', 'fixed filters are sent too' );
	assert.equal( h.calls.replaced[ 0 ], 'https://example.com/cook/?bfrc_filter%5Bdiet%5D=vegan', 'but never put in the page URL' );
	assert.equal( h.state.filtered, true );
	// A page link: delegated from the nav, scrolls the list into view.
	let scrolled = 0;
	const link = { getAttribute: () => '2', closest: ( sel ) => sel === '.bfrc-list' ? { scrollIntoView: () => scrolled++ } : null };
	await h.actions.paginate( { preventDefault: () => prevented++, target: { closest: ( sel ) => sel === 'a[data-bfrc-page]' ? link : null } } );
	assert.equal( h.state.query.page, 2 );
	assert.equal( scrolled, 1 );
	assert.ok( h.calls.fetch[ 1 ].endsWith( '&page=2&per_page=4' ) );
	assert.equal( h.calls.replaced[ 1 ], 'https://example.com/cook/?bfrc_filter%5Bdiet%5D=vegan&bfrc_page=2' );
	await h.actions.paginate( { preventDefault: () => prevented++, target: { closest: () => null } } );
	assert.equal( prevented, 2, 'a click elsewhere in the nav is not a page' );
	// Reset: fields cleared, state cleared, loaded.
	const fields = [ { value: 'vegan' }, { value: 'pie' } ];
	await h.actions.reset( { preventDefault: () => prevented++, target: { closest: () => ( { querySelectorAll: () => fields } ) } } );
	assert.deepEqual( fields.map( ( f ) => f.value ), [ '', '' ] );
	assert.deepEqual( [ h.state.query.s, h.state.query.filter, h.state.query.page ], [ '', {}, 1 ] );
	assert.equal( h.calls.replaced[ 2 ], 'https://example.com/cook/' );
	assert.equal( h.state.filtered, false );
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
	h.setElement( h.pagination );
	h.callbacks.renderPagination();
	assert.equal( h.pagination.innerHTML, '<ul class="page-numbers"><li><a class="prev page-numbers" href="https://example.com/cook/?bfrc_s=pie" data-bfrc-page="1">&laquo; Previous</a></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=pie" data-bfrc-page="1">1</a></li><li><span aria-current="page" class="page-numbers current">2</span></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=pie&#038;bfrc_page=3" data-bfrc-page="3">3</a></li><li><a class="next page-numbers" href="https://example.com/cook/?bfrc_s=pie&#038;bfrc_page=3" data-bfrc-page="3">Next &raquo;</a></li></ul>' );
	const empty = await harness( { respond: () => response( [], 0, 0 ) } );
	await empty.actions.submit( { preventDefault() {} } );
	empty.callbacks.renderResults();
	assert.equal( empty.results.innerHTML, '<p class="bfrc-empty">No recipes match. Try fewer filters.</p>' );
	assert.equal( empty.state.hasPages, false );
	assert.equal( empty.state.status, '0 recipes shown.' );
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
