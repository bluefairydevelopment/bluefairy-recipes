const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { JSDOM } = require( 'jsdom' );
const profile = require( '../../assets/admin/writer-profile.js' );

test( 'selection prefers the thumbnail, falls back to the full URL, and clears on nothing', () => {
	assert.deepEqual( profile.selection( { id: 12, url: 'https://x/full.jpg', sizes: { thumbnail: { url: 'https://x/thumb.jpg' } } } ), { id: '12', src: 'https://x/thumb.jpg' } );
	assert.deepEqual( profile.selection( { id: 7, url: 'https://x/full.jpg' } ), { id: '7', src: 'https://x/full.jpg' } );
	assert.deepEqual( profile.selection( null ), { id: '0', src: '' } );
	assert.deepEqual( profile.selection( { id: 0, url: 'https://x/a.jpg' } ), { id: '0', src: '' }, 'no id: cleared, never a dangling preview' );
} );

test( 'apply writes the id, swaps the preview image and toggles Remove', () => {
	const dom = new JSDOM( '<table><tr><td class="bfrc-image-picker"><input type="hidden" class="bfrc-image-picker__id" value="0"><span class="bfrc-image-picker__preview"></span><button class="bfrc-image-picker__remove" hidden>Remove</button></td></tr></table>' );
	const doc = dom.window.document;
	const picker = doc.querySelector( '.bfrc-image-picker' );
	profile.apply( picker, { id: '12', src: 'https://x/thumb.jpg' }, doc );
	assert.equal( picker.querySelector( '.bfrc-image-picker__id' ).value, '12' );
	assert.equal( picker.querySelector( 'img' ).getAttribute( 'src' ), 'https://x/thumb.jpg' );
	assert.equal( picker.querySelector( '.bfrc-image-picker__remove' ).hidden, false );
	profile.apply( picker, { id: '0', src: '' }, doc );
	assert.equal( picker.querySelector( '.bfrc-image-picker__id' ).value, '0' );
	assert.equal( picker.querySelector( 'img' ), null );
	assert.equal( picker.querySelector( '.bfrc-image-picker__remove' ).hidden, true );
} );
