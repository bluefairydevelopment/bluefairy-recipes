// The public filter form script, run under jsdom (the file is an IIFE: evaluated in the window).
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );

const SOURCE = fs.readFileSync( path.join( __dirname, '../../assets/front/recipes.js' ), 'utf8' );

function form( search ) {
	return '<form class="bfrc-filters" method="get" action="https://example.com/recipes/" data-bfrc-filters>' +
		'<select name="bfrc_filter[diet]"><option value="">All</option><option value="vegan">Vegan</option></select>' +
		'<select name="bfrc_filter[cuisine]"><option value="">All</option><option value="thai">Thai</option></select>' +
		'<input type="search" name="bfrc_s" value="' + ( search || '' ) + '">' +
		'<button type="submit">Filter</button></form>' +
		'<button type="button" data-bfrc-print>Print</button>';
}

// Timers run at once so the 600 ms debounce does not slow the suite; submit is observed, not navigated.
function page( html, opts ) {
	opts = opts || {};
	const virtualConsole = new VirtualConsole();
	const jsdomErrors = [];
	virtualConsole.on( 'jsdomError', ( e ) => { jsdomErrors.push( String( e.message || e ) ); } ); // jsdom cannot navigate; the attempt is what we test.
	const dom = new JSDOM( '<!doctype html><body>' + html + '</body>', { runScripts: 'outside-only', url: 'https://example.com/recipes/' + ( opts.query || '' ), virtualConsole, pretendToBeVisual: true } );
	const win = dom.window;
	if ( opts.beforeScript ) {
		opts.beforeScript( win ); // e.g. a theme that focuses something before our script runs.
	}
	win.setTimeout = ( fn ) => { fn(); return 1; };
	win.clearTimeout = () => {};
	win.print = () => { win.printed = ( win.printed || 0 ) + 1; };
	if ( opts.flag ) {
		win.sessionStorage.setItem( 'bfrcFocus', opts.flag );
	}
	const submits = [];
	const f = win.document.querySelector( 'form' );
	f.addEventListener( 'submit', ( e ) => { if ( ! opts.navigate ) { e.preventDefault(); } submits.push( Array.from( f.elements ).filter( ( el ) => el.name ).map( ( el ) => el.name + ( el.disabled ? ':off' : ':on' ) ) ); } );
	win.eval( SOURCE );
	return { win, doc: win.document, form: f, submits, jsdomErrors };
}

test( 'a changed select submits after the debounce with the empty fields disabled, and they come back on pageshow', () => {
	const p = page( form() );
	const diet = p.doc.querySelector( '[name="bfrc_filter[diet]"]' );
	diet.value = 'vegan';
	diet.dispatchEvent( new p.win.Event( 'change', { bubbles: true } ) );
	assert.deepEqual( p.submits, [ [ 'bfrc_filter[diet]:on', 'bfrc_filter[cuisine]:off', 'bfrc_s:off' ] ], 'only the chosen filter travels' );
	assert.equal( p.doc.querySelector( '[name="bfrc_filter[cuisine]"]' ).disabled, true );
	p.win.dispatchEvent( new p.win.Event( 'pageshow' ) );
	assert.equal( p.doc.querySelector( '[name="bfrc_filter[cuisine]"]' ).disabled, false, 'Back from the bfcache: the field is usable again' );
	assert.equal( p.doc.querySelector( '[name="bfrc_s"]' ).disabled, false );
	assert.equal( p.doc.querySelector( '[name="bfrc_s"]' ).hasAttribute( 'data-bfrc-disabled' ), false );
	assert.match( p.win.sessionStorage.getItem( 'bfrcFocus' ), /^bfrc_filter\[diet\]:\d+$/, 'the changed select asks to be focused again after the reload' );
	const back = page( form(), { query: '?bfrc_filter%5Bdiet%5D=vegan', flag: 'bfrc_filter[diet]:' + Date.now() } );
	assert.equal( back.doc.activeElement, back.doc.querySelector( '[name="bfrc_filter[diet]"]' ), 'a keyboard user lands back on the select, not at the top of the page' );
} );

test( 'a cancelled submit re-enables the fields on pagehide and a field disabled by the theme is left alone', () => {
	const p = page( form() );
	const cuisine = p.doc.querySelector( '[name="bfrc_filter[cuisine]"]' );
	cuisine.disabled = true; // The theme's doing, not ours.
	p.form.dispatchEvent( new p.win.Event( 'submit', { cancelable: true } ) );
	assert.equal( p.doc.querySelector( '[name="bfrc_filter[diet]"]' ).disabled, true );
	p.win.dispatchEvent( new p.win.Event( 'pagehide' ) );
	assert.equal( p.doc.querySelector( '[name="bfrc_filter[diet]"]' ).disabled, false );
	assert.equal( cuisine.disabled, true, 'not marked by us: not touched' );
} );

test( 'typing submits with a focus flag, IME composition waits, and the flag restores the caret once after the reload', () => {
	const p = page( form() );
	const input = p.doc.querySelector( '[name="bfrc_s"]' );
	input.value = 'sou';
	const composing = new p.win.InputEvent( 'input', { bubbles: true, isComposing: true } );
	input.dispatchEvent( composing );
	assert.deepEqual( p.submits, [], 'mid-composition: nothing submitted' );
	input.dispatchEvent( new p.win.InputEvent( 'input', { bubbles: true } ) );
	assert.deepEqual( p.submits, [ [ 'bfrc_filter[diet]:off', 'bfrc_filter[cuisine]:off', 'bfrc_s:on' ] ] );

	assert.match( p.win.sessionStorage.getItem( 'bfrcFocus' ), /^bfrc_s:\d+$/, 'typing asks for focus on the next page, with a timestamp' );
	const after = page( form( 'sou' ), { query: '?bfrc_s=sou', flag: 'bfrc_s:' + Date.now() } );
	assert.equal( after.doc.activeElement, after.doc.querySelector( '[name="bfrc_s"]' ), 'the caret is back in the search box' );
	assert.equal( after.win.sessionStorage.getItem( 'bfrcFocus' ), null, 'consumed: a plain reload will not refocus' );

	const shared = page( form( 'sou' ), { query: '?bfrc_s=sou' } );
	assert.equal( shared.doc.activeElement, shared.doc.body, 'a shared link with a search in the URL does not steal focus' );

	const stale = page( form( 'sou' ), { query: '?bfrc_s=sou', flag: 'bfrc_s:' + ( Date.now() - 60000 ) } );
	assert.equal( stale.doc.activeElement, stale.doc.body, 'a flag from a search that never navigated (a minute old) is ignored' );
	assert.equal( stale.win.sessionStorage.getItem( 'bfrcFocus' ), null, 'and consumed' );

	const cleared = page( form( '' ), { flag: 'bfrc_s:' + Date.now() } );
	assert.equal( cleared.doc.activeElement, cleared.doc.querySelector( '[name="bfrc_s"]' ), 'the visitor cleared the box and the page reloaded: the caret goes back to the empty box' );

	const busy = page( form( 'sou' ) + '<input id="cookie-ok">', { query: '?bfrc_s=sou', flag: 'bfrc_s:' + Date.now(), beforeScript: ( w ) => w.document.getElementById( 'cookie-ok' ).focus() } );
	assert.equal( busy.doc.activeElement, busy.doc.getElementById( 'cookie-ok' ), 'a theme that already placed focus keeps it' );
	assert.equal( busy.win.sessionStorage.getItem( 'bfrcFocus' ), null, 'the flag is still consumed' );
	const busySelect = page( form(), { query: '?bfrc_filter%5Bdiet%5D=vegan', flag: 'bfrc_filter[diet]:' + Date.now(), beforeScript: ( w ) => w.document.querySelector( 'button' ).focus() } );
	assert.equal( busySelect.doc.activeElement, busySelect.doc.querySelector( 'button' ), 'the select branch respects placed focus too' );

	const legacy = page( form( 'sou' ), { query: '?bfrc_s=sou', flag: 'bfrc_s' } );
	assert.equal( legacy.doc.activeElement, legacy.doc.body, 'a flag in the old colon-less format (a tab open across the deploy) is ignored' );
	const corrupt = page( form( 'sou' ), { query: '?bfrc_s=sou', flag: 'bfrc_s:abc' } );
	assert.equal( corrupt.doc.activeElement, corrupt.doc.body, 'a corrupt timestamp is stale, not fresh' );
} );

test( 'committed IME text submits on compositionend', () => {
	const p = page( form() );
	const input = p.doc.querySelector( '[name="bfrc_s"]' );
	input.value = '寿司';
	input.dispatchEvent( new p.win.InputEvent( 'input', { bubbles: true, isComposing: true } ) ); // Chrome/Safari: the last input of a composition still says composing
	assert.deepEqual( p.submits, [] );
	input.dispatchEvent( new p.win.CompositionEvent( 'compositionend', { bubbles: true, data: '寿司' } ) );
	assert.equal( p.submits.length, 1, 'the committed word searches' );
	assert.match( p.win.sessionStorage.getItem( 'bfrcFocus' ), /^bfrc_s:\d+$/ );
} );

test( 'a field named "action" does not shadow the form action', () => {
	// No action attribute at all, but a field named "action": form.action is then that element (truthy),
	// and a script reading it would cancel the submit and navigate to "[object HTMLInputElement]".
	// The attribute is what counts: absent, the script leaves the submit alone.
	const p = page( form().replace( ' action="https://example.com/recipes/"', '' ).replace( '<button type="submit">', '<input type="hidden" name="action" value="injected"><button type="submit">' ), { navigate: true, query: '?bfrc_filter%5Bdiet%5D=vegan' } );
	// jsdom does not implement the named-field shadowing browsers do, so it is reproduced on the instance.
	Object.defineProperty( p.form, 'action', { value: p.form.querySelector( '[name=action]' ), configurable: true } );
	assert.equal( typeof p.form.action, 'object', 'the harness reproduces the shadowing' );
	let prevented = null;
	p.form.addEventListener( 'submit', ( e ) => { prevented = e.defaultPrevented; } ); // Registered after the script's listener, so it sees its decision.
	p.form.requestSubmit();
	assert.equal( prevented, false, 'not cancelled: the form submits itself' );

	const real = page( form(), { navigate: true, query: '?bfrc_filter%5Bdiet%5D=vegan' } );
	let preventedReal = null;
	real.form.addEventListener( 'submit', ( e ) => { preventedReal = e.defaultPrevented; } );
	real.form.requestSubmit();
	assert.equal( preventedReal, true, 'with an action attribute and nothing to send, the script takes over' );
} );

test( 'Filter with every field empty goes to the plain archive URL instead of submitting a bare "?"', () => {
	const p = page( form(), { navigate: true, query: '?bfrc_filter%5Bdiet%5D=vegan' } ); // On a filtered page, everything cleared, Filter pressed.
	p.form.requestSubmit();
	assert.equal( p.submits.length, 1 );
	assert.ok( p.jsdomErrors.some( ( m ) => /navigation/i.test( m ) ), 'the script navigated to the action URL itself (jsdom reports the attempt): ' + p.jsdomErrors.join( ' | ' ) );
	assert.ok( ! p.jsdomErrors.some( ( m ) => /form submission|requestSubmit/i.test( m ) ), 'the form itself did not submit' );

	const filled = page( form( 'soup' ), { navigate: true } );
	filled.form.requestSubmit();
	assert.ok( ! filled.jsdomErrors.some( ( m ) => /navigation/i.test( m ) ), 'with a value the form submits normally: ' + filled.jsdomErrors.join( ' | ' ) );
} );

test( 'the print button prints', () => {
	const p = page( form() );
	p.doc.querySelector( '[data-bfrc-print]' ).click();
	assert.equal( p.win.printed, 1 );
} );
