// Node's own test runner, no dependencies: `node --test tests/js`.
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const rows = require( '../../assets/admin/recipe-rows.js' );

test( 'renumber replaces the row index and the template placeholder, once, leaving other brackets', () => {
	assert.equal( rows.renumber( 'bfrc[ingredients][3][label]', 7 ), 'bfrc[ingredients][7][label]' );
	assert.equal( rows.renumber( 'bfrc[steps][__INDEX__][text]', 0 ), 'bfrc[steps][0][text]' );
	assert.equal( rows.renumber( 'bfrc[ingredients][12][product_id]', 2 ), 'bfrc[ingredients][2][product_id]' );
	assert.equal( rows.renumber( 'bfrc[nutrition][calories]', 5 ), 'bfrc[nutrition][calories]', 'no numeric index: unchanged' );
} );

test( 'move reorders a copy and leaves out-of-range or no-op moves alone', () => {
	const items = [ 'a', 'b', 'c', 'd' ];
	assert.deepEqual( rows.move( items, 0, 2 ), [ 'b', 'c', 'a', 'd' ] );
	assert.deepEqual( rows.move( items, 3, 0 ), [ 'd', 'a', 'b', 'c' ] );
	assert.deepEqual( rows.move( items, 1, 1 ), items );
	assert.deepEqual( rows.move( items, 0, -1 ), items, 'up from the top: unchanged' );
	assert.deepEqual( rows.move( items, 3, 4 ), items, 'down from the bottom: unchanged' );
	assert.deepEqual( items, [ 'a', 'b', 'c', 'd' ], 'the input is not mutated' );
} );

test( 'canAdd stops exactly at the ceiling', () => {
	assert.equal( rows.canAdd( 199, 200 ), true );
	assert.equal( rows.canAdd( 200, 200 ), false );
	assert.equal( rows.canAdd( 0, 1 ), true );
} );

test( 'videoRejected warns only when something was typed and nothing was kept', () => {
	assert.equal( rows.videoRejected( 'https://example.com/x', '' ), true );
	assert.equal( rows.videoRejected( 'https://example.com/x', null ), true );
	assert.equal( rows.videoRejected( 'https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ), false );
	assert.equal( rows.videoRejected( '', '' ), false, 'a deliberate clear' );
	assert.equal( rows.videoRejected( '   ', '' ), false );
} );
