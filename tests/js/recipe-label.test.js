// The Recipe Card picker shows plain titles: REST's title.rendered is HTML ("Mac &amp; Cheese", "&#8217;").
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

test( 'recipeLabel decodes the rendered title and falls back to the id', async () => {
	const { recipeLabel } = await import( '../../src/blocks/recipe-label.js' );
	const decode = ( html ) => html.replace( /&amp;/g, '&' ).replace( /&#8217;/g, '’' );
	assert.equal( recipeLabel( { id: 7, title: { rendered: 'Mac &amp; Cheese' } }, decode ), 'Mac & Cheese' );
	assert.equal( recipeLabel( { id: 8, title: { rendered: 'Gran&#8217;s Pie' } }, decode ), 'Gran’s Pie' );
	assert.equal( recipeLabel( { id: 9, title: { rendered: '' } }, decode ), '9', 'an untitled recipe shows its id' );
} );

test( 'the Recipe Card picker labels every option with recipeLabel and core decodeEntities', () => {
	const source = fs.readFileSync( path.join( __dirname, '../../src/blocks/recipe-card/index.js' ), 'utf8' );
	assert.match( source, /import \{ decodeEntities \} from '@wordpress\/html-entities';/ );
	assert.doesNotMatch( source, /label: [a-z]+\.title\.rendered/, 'no raw rendered title as a label' );
	assert.equal( ( source.match( /recipeLabel\( [a-z]+, decodeEntities \)/g ) || [] ).length, 2, 'both the search results and the current recipe' );
} );
