// The DOM wiring of the recipe editor, run under jsdom.
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { JSDOM } = require( 'jsdom' );
const rows = require( '../../assets/admin/recipe-rows.js' );
const init = require( '../../assets/admin/recipe-editor.js' );

// Mirrors RecipeEditor::ingredient_row(): with several types a select + extras; with one type a hidden type input in the actions cell.
function row( i, opts ) {
	opts = opts || {};
	const typeCell = opts.single
		? ''
		: '<td><select name="bfrc[ingredients][' + i + '][type]" class="bfrc-rows__type" aria-label="Ingredient 0 type"><option value="plain">Plain</option><option value="internal">Shop</option></select>' +
			'<input type="text" class="bfrc-rows__extra" data-bfrc-type="internal" data-bfrc-field="product_id" ' + ( opts.extra ? '' : 'hidden disabled ' ) + 'name="bfrc[ingredients][' + i + '][product_id]"' + ( opts.noPlaceholder ? '' : ' placeholder="Product"' ) + '></td>';
	return '<tr class="bfrc-rows__row"><td class="bfrc-rows__handle" draggable="true" title="Drag to reorder"><span aria-hidden="true">⋮⋮</span></td>' +
		'<td><input type="text" class="widefat" name="bfrc[ingredients][' + i + '][measure]" value="m' + i + '" placeholder="2 cups" aria-label="Ingredient 0 measure"></td>' +
		'<td><input type="text" class="widefat" name="bfrc[ingredients][' + i + '][label]" value="l' + i + '" placeholder="flour" aria-label="Ingredient 0 name"></td>' +
		typeCell +
		'<td class="bfrc-rows__actions">' + ( opts.single ? '<input type="hidden" name="bfrc[ingredients][' + i + '][type]" value="plain">' : '' ) + '<button type="button" class="button-link bfrc-rows__up" aria-label="Move up">▲</button> <button type="button" class="button-link bfrc-rows__down" aria-label="Move down">▼</button> <button type="button" class="button-link bfrc-rows__remove" aria-label="Remove">✕</button></td></tr>';
}

// Mirrors RecipeEditor::step_row().
function step( i, text ) {
	return '<li class="bfrc-rows__row"><div class="bfrc-rows__grid"><span class="bfrc-rows__handle" draggable="true" title="Drag to reorder"><span aria-hidden="true">⋮⋮</span></span>' +
		'<textarea class="widefat" rows="3" name="bfrc[steps][' + i + '][text]" aria-label="Step 0">' + ( text || '' ) + '</textarea>' +
		'<span class="bfrc-rows__actions"><button type="button" class="button-link bfrc-rows__up" aria-label="Move up">▲</button> <button type="button" class="button-link bfrc-rows__down" aria-label="Move down">▼</button> <button type="button" class="button-link bfrc-rows__remove" aria-label="Remove">✕</button></span></div></li>';
}

function page( opts ) {
	opts = opts || {};
	const rowOpts = { single: !!opts.single, noPlaceholder: !!opts.noPlaceholder };
	const html = '<div class="postbox closed hide-if-js"><button type="button" class="handlediv" aria-expanded="false"></button><div class="inside">' +
		'<div class="bfrc-editor bfrc-rows" data-bfrc-rows="ingredients" data-bfrc-max="' + ( opts.max || 200 ) + '">' +
		'<table><tbody>' + row( 0, rowOpts ) + row( 1, rowOpts ) + row( 2, rowOpts ) + '</tbody></table>' +
		'<template class="bfrc-rows__template">' + row( '__INDEX__', rowOpts ) + '</template>' +
		'<p><button type="button" class="button bfrc-rows__add">Add</button> <span class="bfrc-rows__limit"></span></p>' +
		'<input type="hidden" name="bfrc_recipe_nonce" value="n"><input type="hidden" name="bfrc[_has][ingredients]" value="1"></div>' +
		'<div class="bfrc-editor bfrc-rows" data-bfrc-rows="steps" data-bfrc-max="' + ( opts.max || 200 ) + '">' +
		'<ol class="bfrc-rows__list">' + step( 0, 'Mix' ) + step( 1, 'Bake' ) + '</ol>' +
		'<template class="bfrc-rows__template">' + step( '__INDEX__' ) + '</template>' +
		'<p><button type="button" class="button bfrc-rows__add">Add</button> <span class="bfrc-rows__limit"></span></p>' +
		'<input type="hidden" name="bfrc_recipe_nonce" value="n"><input type="hidden" name="bfrc[_has][steps]" value="1"></div>' +
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
			rowLabels: { ingredients: { measure: 'Ingredient %d measure', label: 'Ingredient %d name', type: 'Ingredient %d type', extra: 'Ingredient %1$d %2$s' }, steps: { text: 'Step %d' } },
		},
	};
	win.spoken = [];
	win.wp = opts.wp || {};
	win.wp.a11y = { speak: ( t ) => win.spoken.push( t ) };
	const api = init( win.document, win );
	const box = win.document.querySelector( '[data-bfrc-rows="ingredients"]' );
	const steps = win.document.querySelector( '[data-bfrc-rows="steps"]' );
	return { win, doc: win.document, box, steps, api, rowsOf: () => Array.from( box.querySelectorAll( 'tbody .bfrc-rows__row' ) ), stepsOf: () => Array.from( steps.querySelectorAll( '.bfrc-rows__list .bfrc-rows__row' ) ) };
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
	assert.equal( p.api.countFields(), 3 * 3 + 2 + 2 + 2 + 1, 'three enabled fields per ingredient row, two steps, two nonces, two sentinels and the video field' );
	assert.deepEqual( p.stepsOf().map( ( r ) => r.querySelector( 'textarea' ).getAttribute( 'aria-label' ) ), [ 'Step 1', 'Step 2' ], 'steps are labelled too' );
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
	assert.equal( p.api.countFields(), 3 * 3 + 2 + 2 + 2 + 1 + 1 );
	select.value = 'plain';
	select.dispatchEvent( new p.win.Event( 'change', { bubbles: true } ) );
	assert.equal( extra.disabled, true );
} );

test( 'the field-count warning appears when the form would exceed max_input_vars and goes away again', () => {
	const p = page( { maxInputVars: 140 } );
	assert.equal( p.doc.querySelector( '.bfrc-fields-notice' ), null, '16 fields + 120 margin < 140' );
	click( p, p.box.querySelector( '.bfrc-rows__add' ) );
	click( p, p.box.querySelector( '.bfrc-rows__add' ) );
	assert.equal( p.doc.querySelectorAll( '.bfrc-fields-notice' ).length, 1, 'one notice, not one per box (it would be announced twice)' );
	assert.equal( p.box.querySelector( '.bfrc-fields-notice' ).textContent, 'Too many fields.', '22 + 120 > 140' );
	click( p, p.rowsOf()[ 0 ].querySelector( '.bfrc-rows__remove' ) );
	click( p, p.rowsOf()[ 0 ].querySelector( '.bfrc-rows__remove' ) );
	assert.equal( p.doc.querySelector( '.bfrc-fields-notice' ), null );
	const q = page( { maxInputVars: 0 } );
	for ( let i = 0; i < 20; i++ ) {
		click( q, q.box.querySelector( '.bfrc-rows__add' ) );
	}
	assert.equal( q.doc.querySelector( '.bfrc-fields-notice' ), null, 'unknown ceiling: never warns' );
} );

test( 'drag starts from the handle only and reorders on drop', () => {
	const p = page();
	const r = p.rowsOf();
	const input = r[ 0 ].querySelector( '[name$="[measure]"]' );
	input.dispatchEvent( new p.win.Event( 'dragstart', { bubbles: true } ) );
	r[ 2 ].dispatchEvent( new p.win.Event( 'dragover', { bubbles: true, cancelable: true } ) );
	p.doc.dispatchEvent( new p.win.Event( 'dragend' ) );
	assert.deepEqual( p.rowsOf().map( ( x ) => x.querySelector( '[name$="[label]"]' ).value ), [ 'l0', 'l1', 'l2' ], 'a drag from inside a field is text selection, not a row drag' );
	const start = new p.win.Event( 'dragstart', { bubbles: true } );
	const transfer = { effectAllowed: '', data: {}, setData( type, value ) { this.data[ type ] = value; } };
	Object.defineProperty( start, 'dataTransfer', { value: transfer } );
	r[ 0 ].querySelector( '.bfrc-rows__handle span' ).dispatchEvent( start );
	assert.equal( transfer.effectAllowed, 'move' );
	assert.deepEqual( transfer.data, { 'text/plain': '' }, 'Firefox needs data for a drag to start' );
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
	assert.equal( postbox.classList.contains( 'hide-if-js' ), false, 'a box hidden via Screen Options is shown too' );
	assert.equal( postbox.querySelector( '.handlediv' ).getAttribute( 'aria-expanded' ), 'true' );
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
	p.win.wp.apiFetch = () => Promise.reject( new Error( 'offline' ) );
	p.doc.getElementById( 'bfrc-video-url' ).value = 'https://example.com/again';
	let unhandled = null;
	p.win.addEventListener( 'unhandledrejection', ( e ) => { unhandled = e; } );
	await p.api.checkVideo();
	assert.equal( p.doc.querySelector( '.bfrc-video-notice' ), null, 'a failed read-back changes nothing' );
	assert.equal( unhandled, null, 'and is caught' );
} );

test( 'classic editor: without wp.data nothing subscribes and the rows still work', () => {
	const p = page( { wp: {} } );
	click( p, p.box.querySelector( '.bfrc-rows__add' ) );
	assert.equal( p.rowsOf().length, 4 );
} );

test( 'the free plugin\'s single-type rows (hidden type input in the actions cell) add, renumber and post the type', () => {
	const p = page( { single: true } );
	click( p, p.box.querySelector( '.bfrc-rows__add' ) );
	const last = p.rowsOf()[ 3 ];
	assert.equal( last.querySelector( '.bfrc-rows__actions input[type=hidden]' ).name, 'bfrc[ingredients][3][type]' );
	assert.equal( last.querySelector( '.bfrc-rows__type' ), null );
	assert.equal( p.api.countFields(), 4 * 3 + 2 + 2 + 2 + 1 );
} );

test( 'an extra field without a placeholder is labelled by its field name', () => {
	const p = page( { noPlaceholder: true } );
	assert.equal( p.rowsOf()[ 0 ].querySelector( '.bfrc-rows__extra' ).getAttribute( 'aria-label' ), 'Ingredient 1 product_id' );
} );

test( 'steps: add, move and remove work on the list markup and renumber the step labels', () => {
	const p = page();
	click( p, p.steps.querySelector( '.bfrc-rows__add' ) );
	assert.deepEqual( p.stepsOf().map( ( r ) => r.querySelector( 'textarea' ).name ), [ 'bfrc[steps][0][text]', 'bfrc[steps][1][text]', 'bfrc[steps][2][text]' ] );
	assert.equal( p.doc.activeElement, p.stepsOf()[ 2 ].querySelector( 'textarea' ) );
	click( p, p.stepsOf()[ 1 ].querySelector( '.bfrc-rows__down' ) );
	assert.deepEqual( p.stepsOf().map( ( r ) => r.querySelector( 'textarea' ).value ), [ 'Mix', '', 'Bake' ] );
	assert.deepEqual( p.stepsOf().map( ( r ) => r.querySelector( 'textarea' ).getAttribute( 'aria-label' ) ), [ 'Step 1', 'Step 2', 'Step 3' ] );
	click( p, p.stepsOf()[ 0 ].querySelector( '.bfrc-rows__remove' ) );
	assert.deepEqual( p.stepsOf().map( ( r ) => r.querySelector( 'textarea' ).name ), [ 'bfrc[steps][0][text]', 'bfrc[steps][1][text]' ] );
} );
