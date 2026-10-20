/**
 * The `bfrc/recipes` store definition, built against an `api` object so the
 * actions and callbacks run under node --test without the Interactivity
 * runtime: `api.state()` is the store's state proxy, `api.getElement()` the
 * directive's element, `api.getConfig()` the server config, and
 * `api.fetch`, `api.setTimeout`, `api.clearTimeout`, `api.history` the
 * browser's.
 */
import { DEBOUNCE, buildParams, buildPageUrl, gridHtml, paginationHtml, statusText } from './query.js';

export function createStore( api ) {
	let timer = null;

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
		return buildPageUrl( state.baseUrl, { s: state.query.s, filter: state.query.filter, page }, config.vars );
	}

	async function load() {
		const state = api.state();
		const config = api.getConfig();
		const url = state.restUrl + ( state.restUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + buildParams( state.query, state.fixed, state.perPage );
		state.loading = true;
		state.error = false;
		state.status = config.loadingText;
		try {
			const response = await api.fetch( url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' } );
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}
			const items = await response.json();
			if ( ! Array.isArray( items ) ) {
				throw new Error( 'not a list' );
			}
			state.cards = items.map( ( item ) => String( item.html || '' ) );
			state.total = Number( response.headers.get( 'X-WP-Total' ) ) || 0;
			state.totalPages = Number( response.headers.get( 'X-WP-TotalPages' ) ) || 0;
			state.hasPages = state.totalPages > 1;
			state.status = statusText( config.foundText, state.cards.length );
			state.version = ( state.version || 0 ) + 1;
			api.history.replaceState( null, '', urlFor( state, state.query.page ) );
		} catch ( e ) {
			state.error = true;
			state.status = config.errorText;
		}
		state.loading = false;
	}

	function filtered( state ) {
		if ( state.query.s ) {
			return true;
		}
		return Object.keys( state.query.filter || {} ).some( ( code ) => !! state.query.filter[ code ] );
	}

	return {
		// The state's values come from the server (wp_interactivity_state()): store() merges this
		// definition OVER the server state, so a default here would overwrite what the page sent.
		// Only the derived getter is defined.
		state: {
			get filtered() {
				return filtered( api.state() );
			},
		},
		actions: {
			/** Typing in the search box (not mid-composition) or a finished IME composition. */
			keyword( event ) {
				if ( event.isComposing ) {
					return;
				}
				const state = api.state();
				state.query.s = String( event.target.value || '' ).slice( 0, 100 );
				state.query.page = 1;
				schedule();
			},
			/** A select changed. */
			filter( event ) {
				const state = api.state();
				const code = event.target.getAttribute( 'data-bfrc-code' );
				if ( ! code ) {
					return;
				}
				state.query.filter[ code ] = String( event.target.value || '' );
				state.query.page = 1;
				schedule();
			},
			/** The Filter button: at once. After a failed load the form submits as a plain form. */
			submit( event ) {
				const state = api.state();
				if ( state.error ) {
					return;
				}
				event.preventDefault();
				cancel();
				return load();
			},
			/** The Reset link: clear every field and reload. */
			reset( event ) {
				const state = api.state();
				if ( state.error ) {
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
				return load();
			},
			/** A pagination link (delegated from the nav). */
			paginate( event ) {
				const state = api.state();
				const link = event.target.closest ? event.target.closest( 'a[data-bfrc-page]' ) : null;
				if ( ! link || state.error ) {
					return;
				}
				event.preventDefault();
				cancel();
				state.query.page = Math.max( 1, Number( link.getAttribute( 'data-bfrc-page' ) ) || 1 );
				const list = link.closest( '.bfrc-list' );
				if ( list && typeof list.scrollIntoView === 'function' ) {
					list.scrollIntoView( { block: 'start' } );
				}
				return load();
			},
		},
		callbacks: {
			/** The results region follows the fetched cards. */
			renderResults() {
				const state = api.state();
				const version = state.version;
				if ( ! state.cards || ! version ) {
					return;
				}
				api.getElement().ref.innerHTML = gridHtml( state.cards, api.getConfig().emptyText );
			},
			/** The pagination follows the fetched totals. */
			renderPagination() {
				const state = api.state();
				const version = state.version;
				if ( ! state.cards || ! version ) {
					return;
				}
				const config = api.getConfig();
				api.getElement().ref.innerHTML = paginationHtml( state.query.page, state.totalPages, ( n ) => urlFor( state, n ), { prev: config.prevText, next: config.nextText } );
			},
		},
	};
}
