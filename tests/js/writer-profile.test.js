const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const profile = require( '../../assets/admin/writer-profile.js' );

test( 'selection prefers the thumbnail, falls back to the full URL, and clears on nothing', () => {
	assert.deepEqual( profile.selection( { id: 12, url: 'https://x/full.jpg', sizes: { thumbnail: { url: 'https://x/thumb.jpg' } } } ), { id: '12', src: 'https://x/thumb.jpg' } );
	assert.deepEqual( profile.selection( { id: 7, url: 'https://x/full.jpg' } ), { id: '7', src: 'https://x/full.jpg' } );
	assert.deepEqual( profile.selection( null ), { id: '0', src: '' } );
	assert.deepEqual( profile.selection( { id: 0, url: 'https://x/a.jpg' } ), { id: '0', src: '' }, 'no id: cleared, never a dangling preview' );
} );
