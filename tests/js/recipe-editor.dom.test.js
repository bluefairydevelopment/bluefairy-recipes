// The DOM wiring of the recipe editor, run under jsdom.
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { JSDOM } = require( 'jsdom' );
const rows = require( '../../assets/admin/recipe-rows.js' );
const init = require( '../../assets/admin/recipe-editor.js' );

function row( i, extra ) {
	return '<tr class="bfrc-rows__row"><td class="bfrc-rows__handle" draggable="true"><span aria-hidden="true">⋮⋮</span></td>' +
		'<td><input type="text" name="bfrc[ingredients][' + i + '][measure]" value="m' + i + '"></td>' +
		'<td><input type="text" name="bfrc[ingredients][' + i + '][label]" value="l' + i + '"></td>' +
		'<td><select name="bfrc[ingredients][' + i + '][type]" class="bfrc-rows__type"><option value="plain">Plain</option><option value="internal">Shop</option></select>' +
		'<input type="text" class="bfrc-rows__extra" data-bfrc-type="internal" data-bfrc-field="product_id" ' + ( extra ? '' : 'hidden disabled ' ) + 'name="bfrc[ingredients][' + i + '][product_id]" placeholder="Product"></td>' +
		'<td class="bfrc-rows__actions"><button type="button" class="bfrc-rows__up">▲</button><button type="button" class="bfrc-rows__down">▼</button><button type="button" class="bfrc-rows__remove">✕</button></td></tr>';
}

function page( opts ) {
	opts = opts || {};
	const html = '<div class="postbox closed"><div class="inside">' +
		'<div class="bfrc-editor bfrc-rows" data-bfrc-rows="ingredients" data-bfrc-max="' + ( opts.max || 200 ) + '">' +
		'<table><tbody>' + row( 0 ) + row( 1 ) + row( 2 ) + '</tbody></table>' +
		'<template class="bfrc-rows__template">' + row( '__INDEX__' ) + '</template>' +
		'<button type="button" class="bfrc-rows__add">Add</button> <span class="bfrc-rows__limit"></span>' +
		'<input type="hidden" name="bfrc[_has][ingredients]" value="1"></div>' +
		'<div class="bfrc-editor bfrc-editor--media"><p><input type="text" id="bfrc-video-url" name="bfrc[video_url]" value="' + ( opts.video || '' ) + '"></p><p class="description">d</p></div>' +
		'</div></div>';
	const dom = new JSDOM( '<!doctype html><body>' + html + '</body>', { pretendToBeVisual: true } );
	const win = dom.window;
	win.bfrcRecipeRows = rows;
	win.bfrcRecipeEditor = {
		maxRows: '200',
		maxInputVars: String( opts.maxInputVars === undefined ? 1000 : opts.maxInputVars ),
		restPath: opts.restPath || '',
		videoKey: '_bfrc_video_url',
		i18n: {
			maxRows: 'No more rows.',
			tooManyFields: 'Too many fields.',
			moved: 'Moved to position %d.',
			videoRejected: 'Refused: %s',
			rowLabels: { ingredients: { measure: 'Ingredient %d measure', label: 'Ingredient %d name', type: 'Ingredient %d type', extra: 'Ingredient %1$d %2$s' } },
		},
	};
	win.spoken = [];
	win.wp = Object.assign( { a11y: { speak: ( t ) => win.spoken.push( t ) } }, opts.wp || {} );
	const api = init( win.document, win );
	const box = win.document.querySelector( '[data-bfrc-rows]' );
	return { win, doc: win.document, box, api, rowsOf: () => Array.from( box.querySelectorAll( 'tbody .bfrc-rows__row' ) ) };
}

function names( p ) {
	return p.rowsOf().map( ( r ) => r.querySelector( '[name$="[label]"]' ).name );
}

function click( p, el ) {
	el.dispatchEvent( new p.win.MouseEvent( 'click', { bubbles: true } ) );
}

test( 'init labels every row and disables the extras of other types', () => {
	const p = page();
	assert.deepEqual( p.rowsOf().map( ( r ) => r.querySelector( '[name$="[label]"]' ).getAttribute( 'aria-label' ) ), [ 'Ingredient 1 name', 'Ingredient 2 name', 'Ingredient 3 name' ] );
	assert.equal( p.rowsOf()[ 0 ].querySelector( '.bfrc-rows__extra' ).getAttribute( 'aria-label' ), 'Ingredient 1 Product' );
	assert.ok( p.rowsOf()[ 0 ].querySelector( '.bfrc-rows__extra' ).disabled, 'an inactive type is disabled, so it is not posted' );
	assert.equal( p.api.countFields(), 3 * 3 + 1 + 1, 'three enabled fields per row plus the sentinel and the video field' );
} );

test( 'add appends a renumbered row from the template, focuses it, and stops at the ceiling with a note', () => {
	const p = page( { max: 4 } );
	click( p, p.box.querySelector( '.bfrc-rows__add' ) );
	assert.deepEqual( names( p ), [ 'bfrc[ingredients][0][label]', 'bfrc[ingredients][1][label]', 'bfrc[ingredients][2][label]', 'bfrc[ingredients][3][label]' ] );
	assert.equal( p.doc.activeElement, p.rowsOf()[ 3 ].querySelector( '[name$="[measure]"]' ), 'focus lands on the new row' );
	assert.equal( p.rowsOf()[ 3 ].querySelector( '[name$="[label]"]' ).getAttribute( 'aria-label' ), 'Ingredient 4 name' );
	click( p, p.box.querySelector( '.bfrc-rows__add' ) );
	assert.equal( p.rowsOf().length, 4, 'ceiling' );
	assert.equal( p.box.querySelector( '.bfrc-rows__limit' ).textContent, 'No more rows.' );
	assert.deepEqual( p.win.spoken, [ 'No more rows.' ] );
} );

test( 'remove renumbers the rows that remain', () => {
	const p = page();
	click( p, p.rowsOf()[ 1 ].querySelector( '.bfrc-rows__remove' ) );
	assert.deepEqual( p.rowsOf().map( ( r ) => r.querySelector( '[name$="[label]"]' ).value ), [ 'l0', 'l2' ] );
	assert.deepEqual( names( p ), [ 'bfrc[ingredients][0][label]', 'bfrc[ingredients][1][label]' ] );
} );

test( 'up and down move one row, renumber, announce and keep focus on the button', () => {
	const p = page();
	click( p, p.rowsOf()[ 2 ].querySelector( '.bfrc-rows__up' ) );
	assert.deepEqual( p.rowsOf().map( ( r ) => r.querySelector( '[name$="[label]"]' ).value ), [ 'l0', 'l2', 'l1' ] );
	assert.deepEqual( names( p ), [ 'bfrc[ingredients][0][label]', 'bfrc[ingredients][1][label]', 'bfrc[ingredients][2][label]' ] );
	assert.deepEqual( p.win.spoken, [ 'Moved to position 2.' ] );
	assert.ok( p.doc.activeElement.classList.contains( 'bfrc-rows__up' ) );
	click( p, p.rowsOf()[ 0 ].querySelector( '.bfrc-rows__up' ) );
	assert.deepEqual( p.rowsOf().map( ( r ) => r.querySelector( '[name$="[label]"]' ).value ), [ 'l0', 'l2', 'l1' ], 'top row cannot go up' );
	click( p, p.rowsOf()[ 0 ].querySelector( '.bfrc-rows__down' ) );
	assert.deepEqual( p.rowsOf().map( ( r ) => r.querySelector( '[name$="[label]"]' ).value ), [ 'l2', 'l0', 'l1' ] );
} );

test( 'changing a row type enables only that type\'s extras', () => {
	const p = page();
	const select = p.rowsOf()[ 0 ].querySelector( '.bfrc-rows__type' );
	select.value = 'internal';
	select.dispatchEvent( new p.win.Event( 'change', { bubbles: true } ) );
	const extra = p.rowsOf()[ 0 ].querySelector( '.bfrc-rows__extra' );
	assert.equal( extra.disabled, false );
	assert.equal( extra.hidden, false );
	assert.equal( p.api.countFields(), 3 * 3 + 1 + 1 + 1 );
	select.value = 'plain';
	select.dispatchEvent( new p.win.Event( 'change', { bubbles: true } ) );
	assert.equal( extra.disabled, true );
} );

test( 'the field-count warning appears when the form would exceed max_input_vars and goes away again', () => {
	const p = page( { maxInputVars: 135 } );
	assert.equal( p.box.querySelector( '.bfrc-fields-notice' ), null, '11 fields + 120 margin < 135' );
	click( p, p.box.querySelector( '.bfrc-rows__add' ) );
	click( p, p.box.querySelector( '.bfrc-rows__add' ) );
	assert.equal( p.box.querySelector( '.bfrc-fields-notice' ).textContent, 'Too many fields.', '17 + 120 > 135' );
	click( p, p.rowsOf()[ 0 ].querySelector( '.bfrc-rows__remove' ) );
	click( p, p.rowsOf()[ 0 ].querySelector( '.bfrc-rows__remove' ) );
	assert.equal( p.box.querySelector( '.bfrc-fields-notice' ), null );
	const q = page( { maxInputVars: 0 } );
	for ( let i = 0; i < 20; i++ ) {
		click( q, q.box.querySelector( '.bfrc-rows__add' ) );
	}
	assert.equal( q.box.querySelector( '.bfrc-fields-notice' ), null, 'unknown ceiling: never warns' );
} );

test( 'drag starts from the handle only and reorders on drop', () => {
	const p = page();
	const r = p.rowsOf();
	const input = r[ 0 ].querySelector( '[name$="[measure]"]' );
	input.dispatchEvent( new p.win.Event( 'dragstart', { bubbles: true } ) );
	r[ 2 ].dispatchEvent( new p.win.Event( 'dragover', { bubbles: true, cancelable: true } ) );
	p.doc.dispatchEvent( new p.win.Event( 'dragend' ) );
	assert.deepEqual( p.rowsOf().map( ( x ) => x.querySelector( '[name$="[label]"]' ).value ), [ 'l0', 'l1', 'l2' ], 'a drag from inside a field is text selection, not a row drag' );
	r[ 0 ].querySelector( '.bfrc-rows__handle span' ).dispatchEvent( new p.win.Event( 'dragstart', { bubbles: true } ) );
	const over = new p.win.Event( 'dragover', { bubbles: true, cancelable: true } );
	r[ 2 ].dispatchEvent( over );
	assert.ok( over.defaultPrevented, 'a drop is allowed over another row of the same list' );
	return new Promise( ( resolve ) => p.win.requestAnimationFrame( () => {
		p.doc.dispatchEvent( new p.win.Event( 'dragend' ) );
		assert.deepEqual( p.rowsOf().map( ( x ) => x.querySelector( '[name$="[label]"]' ).value ), [ 'l1', 'l2', 'l0' ] );
		assert.deepEqual( names( p ), [ 'bfrc[ingredients][0][label]', 'bfrc[ingredients][1][label]', 'bfrc[ingredients][2][label]' ] );
		resolve();
	} ) );
} );

test( 'an invalid field opens its collapsed box so the browser can show the message', () => {
	const p = page();
	const postbox = p.doc.querySelector( '.postbox' );
	assert.ok( postbox.classList.contains( 'closed' ) );
	p.doc.getElementById( 'bfrc-video-url' ).dispatchEvent( new p.win.Event( 'invalid', { bubbles: false } ) );
	assert.equal( postbox.classList.contains( 'closed' ), false );
} );

test( 'block editor: after a meta-box save the stored video URL is read back and a refusal is shown inline, then cleared', async () => {
	let listener = null;
	let saving = false;
	let stored = '';
	const fetches = [];
	const wp = {
		data: {
			subscribe: ( fn ) => { listener = fn; },
			select: () => ( { isSavingMetaBoxes: () => saving } ),
		},
		apiFetch: ( args ) => { fetches.push( args.path ); return Promise.resolve( { meta: { _bfrc_video_url: stored } } ); },
	};
	const p = page( { restPath: '/wp/v2/bfrc_recipe/41', video: 'https://example.com/nope', wp } );
	assert.ok( listener, 'subscribed' );
	saving = true; listener();
	saving = false; listener();
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	assert.deepEqual( fetches, [ '/wp/v2/bfrc_recipe/41?context=edit&_fields=meta' ] );
	const notice = p.doc.querySelector( '.bfrc-video-notice' );
	assert.equal( notice.textContent, 'Refused: https://example.com/nope' );
	assert.equal( notice.getAttribute( 'role' ), 'alert' );
	stored = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
	p.doc.getElementById( 'bfrc-video-url' ).value = 'https://youtu.be/dQw4w9WgXcQ';
	saving = true; listener();
	saving = false; listener();
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	assert.equal( p.doc.querySelector( '.bfrc-video-notice' ), null, 'a stored value clears the notice' );
	p.doc.getElementById( 'bfrc-video-url' ).value = '';
	saving = true; listener();
	saving = false; listener();
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	assert.equal( fetches.length, 2, 'an empty field is a deliberate clear: no read-back' );
	wp.apiFetch = () => Promise.reject( new Error( 'offline' ) );
	p.doc.getElementById( 'bfrc-video-url' ).value = 'https://example.com/again';
	saving = true; listener();
	saving = false; listener();
	await p.api.checkVideo();
	assert.equal( p.doc.querySelector( '.bfrc-video-notice' ), null, 'a failed read-back changes nothing and does not throw' );
} );

test( 'classic editor: without wp.data nothing subscribes and the rows still work', () => {
	const p = page( { wp: {} } );
	click( p, p.box.querySelector( '.bfrc-rows__add' ) );
	assert.equal( p.rowsOf().length, 4 );
} );
