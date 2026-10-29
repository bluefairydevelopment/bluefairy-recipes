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
