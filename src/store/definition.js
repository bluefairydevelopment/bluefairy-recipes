/**
 * The `bfrc/recipes` store definition, built against an `api` object so the
 * actions and callbacks run under node --test without the Interactivity
 * runtime: `api.state()` is the store's state proxy (every value comes from
 * the server: this definition sets none, since store() would merge a default
 * over the page's), `api.getElement()` the directive's element,
 * `api.getConfig()` the server config, `api.hasList()` whether a live
 * results region exists on the page, and `api.fetch`, `api.setTimeout`,
 * `api.clearTimeout`, `api.history`, `api.abortController` the browser's.
 */
import { DEBOUNCE, buildParams, buildPageUrl, gridHtml, paginationHtml, statusText, isFiltered } from './query.js';

export function createStore( api ) {
	let timer = null;
	let seq = 0; // The latest request's number: a response for an earlier one is dropped.
	let inflight = null;

	function ready( state ) {
		// No list seeded the store, or no live list is on the page (a Filters block alone): the plain form stands.
		return !! ( state && state.restUrl ) && ( typeof api.hasList !== 'function' || api.hasList() );
	}

	function schedule() {
		const config = api.getConfig();
		api.clearTimeout( timer );
		timer = api.setTimeout( () => {
			timer = null;
			return load();
		}, Number( config.debounce ) || DEBOUNCE );
	}

	function cancel() {
		api.clearTimeout( timer );
		timer = null;
	}

	function urlFor( state, page ) {
		const config = api.getConfig();
		return buildPageUrl( state.baseUrl, { s: state.query.s, filter: state.query.filter, page }, config.vars, state.fixed, config.searchMax );
	}

	async function load() {
		const state = api.state();
		const config = api.getConfig();
		const mine = ++seq;
		const wantFocus = focusCurrentPage; // Taken by this request alone: a request a keystroke supersedes must not move focus later.
		focusCurrentPage = false;
		if ( inflight && typeof inflight.abort === 'function' ) {
			inflight.abort(); // The previous request's answer would be stale.
		}
		inflight = typeof api.abortController === 'function' ? api.abortController() : null;
		state.loading = true;
		state.error = false;
		state.status = config.loadingText;
		state.filtered = isFiltered( state.query );
		try {
			const url = state.restUrl + ( state.restUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + buildParams( state.query, state.fixed, state.perPage, config.searchMax );
			const response = await api.fetch( url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: inflight ? inflight.signal : undefined } );
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}
			const items = await response.json();
			if ( mine !== seq ) {
				return; // A newer request has been sent; its answer is the one to show.
			}
			if ( ! Array.isArray( items ) ) {
				throw new Error( 'not a list' );
			}
			state.cards = items.map( ( item ) => String( item.html || '' ) );
			state.total = Number( response.headers.get( 'X-WP-Total' ) ) || 0;
			state.totalPages = Number( response.headers.get( 'X-WP-TotalPages' ) ) || 0;
			state.hasPages = state.totalPages > 1;
			state.status = statusText( config.foundOneText, config.foundText, state.cards.length );
			state.version = ( state.version || 0 ) + 1;
			if ( wantFocus ) {
				focusVersion = state.version; // renderPagination() moves focus once it has written this version's nav.
			}
			api.history.replaceState( null, '', urlFor( state, state.query.page ) );
		} catch ( e ) {
			if ( mine !== seq ) {
				return; // Aborted by a newer request, or an old failure: nothing to report.
			}
			state.error = true;
			state.status = config.errorText;
		}
		if ( mine === seq ) {
			state.loading = false;
			inflight = null;
		}
	}

	function focus( element ) {
		if ( element && typeof element.focus === 'function' ) {
			element.focus( { preventScroll: true } ); // The list, not the nav, is what was scrolled into view.
		}
	}

	let focusCurrentPage = false; // Set by paginate(); taken by the next load().
	let focusVersion = 0; // The answer whose nav should receive focus, once rendered.
	let renderedResults = 0; // The version each callback last wrote: the watchers re-run on every state read they track (the page write before a fetch, the cards write), one DOM write per answer is enough.
	let renderedPagination = 0;

	return {
		state: {},
		actions: {
			/** Typing in the search box (not mid-composition) or a finished IME composition. */
			keyword( event ) {
				const state = api.state();
				if ( event.isComposing || ! ready( state ) ) {
					return;
				}
				state.query.s = String( event.target.value || '' ).slice( 0, Number( api.getConfig().searchMax ) || 100 );
				state.query.page = 1;
				schedule();
			},
			/** A select changed. */
			filter( event ) {
				const state = api.state();
				const code = event.target.getAttribute( 'data-bfrc-code' );
				if ( ! code || ! ready( state ) ) {
					return;
				}
				state.query.filter[ code ] = String( event.target.value || '' );
				state.query.page = 1;
				schedule();
			},
			/** The Filter button: at once. After a failed load, or without a store, the form submits as a plain form. */
			submit( event ) {
				const state = api.state();
				if ( ! ready( state ) || state.error ) {
					return;
				}
				event.preventDefault();
				cancel();
				return load();
			},
			/** The Reset link: clear every field and reload; focus goes to the search box. */
			async reset( event ) {
				const state = api.state();
				if ( ! ready( state ) || state.error ) {
					return;
				}
				event.preventDefault();
				cancel();
				const form = event.target.closest( 'form' );
				if ( form ) {
					Array.from( form.querySelectorAll( 'select, input[type=search]' ) ).forEach( ( field ) => {
						field.value = '';
					} );
				}
				state.query.s = '';
				state.query.filter = {};
				state.query.page = 1;
				await load();
				focus( form ? form.querySelector( 'input[type=search]' ) : null );
			},
			/** A pagination link (delegated from the nav); focus lands on the new current page. */
			async paginate( event ) {
				const state = api.state();
				const link = event.target.closest ? event.target.closest( 'a[data-bfrc-page]' ) : null;
				if ( ! link || ! ready( state ) || state.error ) {
					return;
				}
				event.preventDefault();
				cancel();
				state.query.page = Math.max( 1, Number( link.getAttribute( 'data-bfrc-page' ) ) || 1 );
				const list = link.closest( '.bfrc-list' );
				if ( list && typeof list.getBoundingClientRect === 'function' && list.getBoundingClientRect().top < 0 && typeof list.scrollIntoView === 'function' ) {
					list.scrollIntoView( { block: 'start' } ); // Only when the list's top is above the viewport.
				}
				focusCurrentPage = true; // The nav is rebuilt once the answer is in: focus moves there, not to the link about to be destroyed.
				return load();
			},
		},
		callbacks: {
			/** The results region follows the fetched cards: one write per answer. */
			renderResults() {
				const state = api.state();
				const version = state.version;
				if ( ! state.cards || ! version || version === renderedResults ) {
					return;
				}
				renderedResults = version;
				api.getElement().ref.innerHTML = gridHtml( state.cards, api.getConfig().emptyText );
			},
			/** The pagination follows the fetched totals: one write per answer, then focus for a page change. */
			renderPagination() {
				const state = api.state();
				const version = state.version;
				if ( ! state.cards || ! version || version === renderedPagination ) {
					return;
				}
				renderedPagination = version;
				const config = api.getConfig();
				const ref = api.getElement().ref;
				ref.innerHTML = paginationHtml( state.query.page, state.totalPages, ( n ) => urlFor( state, n ), { prev: config.prevText, next: config.nextText } );
				if ( focusVersion === version ) {
					focusVersion = 0;
					focus( ref.querySelector ? ref.querySelector( '[aria-current="page"]' ) : null );
					const list = ref.closest ? ref.closest( '.bfrc-list' ) : null;
					if ( list && typeof list.getBoundingClientRect === 'function' && list.getBoundingClientRect().top < 0 && typeof list.scrollIntoView === 'function' ) {
						list.scrollIntoView( { block: 'start' } ); // The new page's cards start above the viewport: bring the list top back.
					}
				}
			},
		},
	};
}
