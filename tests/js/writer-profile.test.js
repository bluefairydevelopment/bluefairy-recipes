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

test( 'the picker opens one media frame per picker, reuses it, writes the selection and clears on Remove', () => {
	const dom = new JSDOM( '<!doctype html><body><table><tr><td class="bfrc-image-picker" data-bfrc-picker="bfrc_avatar_id"><input type="hidden" class="bfrc-image-picker__id" value="0"><span class="bfrc-image-picker__preview"></span><button type="button" class="bfrc-image-picker__choose">Choose</button><button type="button" class="bfrc-image-picker__remove" hidden>Remove</button></td></tr></table></body>', { runScripts: 'outside-only' } );
	const win = dom.window;
	const frames = [];
	const chosen = { toJSON: () => ( { id: 12, url: 'https://x/full.jpg', sizes: { thumbnail: { url: 'https://x/thumb.jpg' } } } ) };
	win.wp = { media: ( args ) => {
		const frame = {
			args,
			opened: 0,
			handlers: {},
			on( ev, fn ) { this.handlers[ ev ] = fn; },
			open() { this.opened++; },
			state() { return { get: () => ( { first: () => chosen } ) }; },
		};
		frames.push( frame );
		return frame;
	} };
	win.bfrcWriterProfile = { i18n: { choose: 'Choose image', use: 'Use' } };
	// Load the production script in the page context (the module.exports branch is taken under Node, so evaluate it in the window instead).
	const fs = require( 'node:fs' );
	const src = fs.readFileSync( require.resolve( '../../assets/admin/writer-profile.js' ), 'utf8' );
	win.eval( src.replace( /typeof module !== 'undefined' && module\.exports/, 'false' ) );
	const doc = win.document;
	const picker = doc.querySelector( '.bfrc-image-picker' );
	const click = ( el ) => el.dispatchEvent( new win.MouseEvent( 'click', { bubbles: true } ) );
	click( picker.querySelector( '.bfrc-image-picker__choose' ) );
	click( picker.querySelector( '.bfrc-image-picker__choose' ) );
	assert.equal( frames.length, 1, 'one frame per picker, reused' );
	assert.equal( frames[ 0 ].opened, 2 );
	assert.equal( frames[ 0 ].args.library.type, 'image' );
	frames[ 0 ].handlers.select();
	assert.equal( picker.querySelector( '.bfrc-image-picker__id' ).value, '12' );
	assert.equal( picker.querySelector( 'img' ).getAttribute( 'src' ), 'https://x/thumb.jpg' );
	click( picker.querySelector( '.bfrc-image-picker__remove' ) );
	assert.equal( picker.querySelector( '.bfrc-image-picker__id' ).value, '0' );
	assert.equal( picker.querySelector( 'img' ), null );
} );
