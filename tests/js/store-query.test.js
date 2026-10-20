// The store's pure helpers: the request, the page URL, and markup built to the byte as the PHP side builds it.
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );

const VARS = { s: 'bfrc_s', filter: 'bfrc_filter', page: 'bfrc_page' };
const load = () => import( '../../src/store/query.js' );

test( 'buildParams: keyword, fixed and chosen filters (fixed wins), page and per_page, bounded', async () => {
	const { buildParams } = await load();
	assert.equal( buildParams( { s: '', filter: {}, page: 1 }, {}, 12 ), 'page=1&per_page=12' );
	const params = buildParams( { s: 'pie & cake', filter: { diet: 'vegan', cuisine: '', meal_type: 'lunch' }, page: 3 }, { meal_type: 'dinner' }, 250 );
	assert.equal( params, 's=pie+%26+cake&filter%5Bdiet%5D=vegan&filter%5Bmeal_type%5D=dinner&page=3&per_page=100' );
	assert.equal( buildParams( { s: 'x'.repeat( 150 ), filter: {}, page: 0 }, {}, 0 ), 's=' + 'x'.repeat( 100 ) + '&page=1&per_page=1', 'keyword cut at 100, page and size floored' );
} );

test( 'buildPageUrl: the page URL for a reload or a shared link, never the fixed filters, page 1 unnamed', async () => {
	const { buildPageUrl } = await load();
	assert.equal( buildPageUrl( 'https://example.com/cook/', { s: '', filter: {}, page: 1 }, VARS ), 'https://example.com/cook/' );
	assert.equal( buildPageUrl( 'https://example.com/cook/', { s: 'pie', filter: { diet: 'vegan', cuisine: '' }, page: 2 }, VARS ), 'https://example.com/cook/?bfrc_s=pie&bfrc_filter%5Bdiet%5D=vegan&bfrc_page=2' );
	assert.equal( buildPageUrl( 'https://example.com/?page_id=5&bfrc_s=old&bfrc_filter%5Bdiet%5D=old&bfrc_page=9', { s: 'new', filter: {}, page: 1 }, VARS ), 'https://example.com/?page_id=5&bfrc_s=new', 'plain permalinks: the page id stays, stale list arguments go' );
	assert.equal( buildPageUrl( '/cook/', { s: '', filter: { diet: 'vegan' }, page: 1 }, VARS ), '/cook/?bfrc_filter%5Bdiet%5D=vegan', 'a relative base stays relative' );
} );

test( 'pageItems mirrors Blocks::page_items (end_size 1, mid_size 2)', async () => {
	const { pageItems } = await load();
	assert.deepEqual( pageItems( 1, 1 ), [ 1 ] );
	assert.deepEqual( pageItems( 3, 5 ), [ 1, 2, 3, 4, 5 ] );
	assert.deepEqual( pageItems( 1, 10 ), [ 1, 2, 3, 'dots', 10 ] );
	assert.deepEqual( pageItems( 5, 10 ), [ 1, 'dots', 3, 4, 5, 6, 7, 'dots', 10 ] );
	assert.deepEqual( pageItems( 10, 10 ), [ 1, 'dots', 8, 9, 10 ] );
	assert.deepEqual( pageItems( 2, 10 ), [ 1, 2, 3, 4, 'dots', 10 ] );
} );

test( 'gridHtml mirrors Renderer::grid, with the empty line escaped', async () => {
	const { gridHtml } = await load();
	assert.equal( gridHtml( [], 'No <b>recipes</b> & "none"' ), '<p class="bfrc-empty">No &lt;b&gt;recipes&lt;/b&gt; &amp; &quot;none&quot;</p>' );
	assert.equal( gridHtml( null, 'x' ), '<p class="bfrc-empty">x</p>' );
	assert.equal( gridHtml( [ '<article>a</article>', '<article>b</article>' ], 'x' ), '<ul class="bfrc-grid"><li class="bfrc-grid__item"><article>a</article></li><li class="bfrc-grid__item"><article>b</article></li></ul>' );
} );

test( 'paginationHtml mirrors Blocks::page_links to the byte', async () => {
	const { paginationHtml } = await load();
	const urlFor = ( n ) => 'https://example.com/cook/?bfrc_s=a%26b' + ( n > 1 ? '&bfrc_page=' + n : '' );
	const labels = { prev: '&laquo; Previous', next: 'Next &raquo;' };
	assert.equal( paginationHtml( 1, 1, urlFor, labels ), '' );
	// The same string the PHP test (BlocksTest::test_page_items_and_page_links…) asserts for page 5 of 10.
	assert.equal(
		paginationHtml( 5, 10, urlFor, labels ),
		'<ul class="page-numbers"><li><a class="prev page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=4" data-bfrc-page="4">&laquo; Previous</a></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b" data-bfrc-page="1">1</a></li><li><span class="page-numbers dots">&hellip;</span></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=3" data-bfrc-page="3">3</a></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=4" data-bfrc-page="4">4</a></li><li><span aria-current="page" class="page-numbers current">5</span></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=6" data-bfrc-page="6">6</a></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=7" data-bfrc-page="7">7</a></li><li><span class="page-numbers dots">&hellip;</span></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=10" data-bfrc-page="10">10</a></li><li><a class="next page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=6" data-bfrc-page="6">Next &raquo;</a></li></ul>'
	);
} );

test( 'the PHP and JS pagination fixtures are the same string', async () => {
	// Guards the mirror: the literal in BlocksTest and the one above must not drift apart.
	const fs = require( 'node:fs' );
	const path = require( 'node:path' );
	const php = fs.readFileSync( path.join( __dirname, '../Unit/Front/BlocksTest.php' ), 'utf8' );
	const js = fs.readFileSync( __filename, 'utf8' );
	const pick = ( src ) => {
		const m = src.match( /'(<ul class="page-numbers"><li><a class="prev page-numbers"[^\n]*?<\/ul>)'/ );
		return m ? m[ 1 ] : null;
	};
	assert.ok( pick( php ), 'the PHP fixture is found' );
	assert.equal( pick( js ), pick( php ) );
} );

test( 'statusText fills the count', async () => {
	const { statusText } = await load();
	assert.equal( statusText( '%s recipes shown.', 3 ), '3 recipes shown.' );
} );
