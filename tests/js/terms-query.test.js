// The fixed-filter dropdowns load every term, not the REST page of 100.
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );

test( 'TERMS_QUERY asks core-data for all pages, by name, empty terms included', async () => {
	const { TERMS_QUERY } = await import( '../../src/blocks/terms-query.js' );
	assert.deepEqual( { ...TERMS_QUERY }, { per_page: -1, orderby: 'name', order: 'asc', hide_empty: false } );
} );

test( 'taxonomies.js loads its terms with TERMS_QUERY', () => {
	const source = require( 'node:fs' ).readFileSync( require( 'node:path' ).join( __dirname, '../../src/blocks/taxonomies.js' ), 'utf8' );
	assert.match( source, /getEntityRecords\( 'taxonomy', taxonomy\.slug, TERMS_QUERY \)/ );
	assert.doesNotMatch( source, /per_page: 100/ );
} );

test( 'the inspector offers only the enabled filter types as fixed filters', async () => {
	const { isOfferedFilter } = await import( '../../src/blocks/terms-query.js' );
	assert.equal( isOfferedFilter( 'bfrc_diet', [ 'diet', 'cuisine' ] ), true );
	assert.equal( isOfferedFilter( 'bfrc_season', [ 'diet', 'cuisine' ] ), false, 'a disabled type is ignored on render, so it is not offered' );
	assert.equal( isOfferedFilter( 'category', [ 'diet' ] ), false, 'not the plugin\'s' );
	assert.equal( isOfferedFilter( 'bfrc_season', undefined ), true, 'no list printed (an old cached page): every plugin type, as before' );
	const source = require( 'node:fs' ).readFileSync( require( 'node:path' ).join( __dirname, '../../src/blocks/taxonomies.js' ), 'utf8' );
	assert.match( source, /isOfferedFilter\( taxonomy\.slug, window\.bfrcEnabledFilters \)/ );
} );
