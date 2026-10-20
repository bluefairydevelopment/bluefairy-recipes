/**
 * The `bfrc/recipes` Interactivity API store: filters a Recipe List block
 * (or `[bfrc_recipes]`) without a reload through GET /bfrc/v1/recipes.
 */
import { store, getElement, getConfig } from '@wordpress/interactivity';
import { createStore } from '../../store/definition.js';

const api = {
	state: () => null,
	getElement,
	getConfig: () => getConfig( 'bfrc/recipes' ),
	fetch: ( url, options ) => window.fetch( url, options ),
	setTimeout: ( fn, ms ) => window.setTimeout( fn, ms ),
	clearTimeout: ( id ) => window.clearTimeout( id ),
	history: window.history,
	abortController: () => ( typeof window.AbortController === 'function' ? new window.AbortController() : null ),
};

const { state } = store( 'bfrc/recipes', createStore( api ) );
api.state = () => state;
