/**
 * The enabled recipe filter types and their terms, for the inspector
 * controls: every taxonomy of the recipe post type whose name carries the
 * plugin's prefix, with all of its terms (name order).
 */
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { TERMS_QUERY } from './terms-query.js';

const PREFIX = 'bfrc_';

export function useRecipeTaxonomies() {
	return useSelect( ( select ) => {
		const { getTaxonomies, getEntityRecords } = select( coreStore );
		const all = getTaxonomies( { type: 'bfrc_recipe', per_page: -1 } ) || [];
		return all
			.filter( ( taxonomy ) => taxonomy.slug.indexOf( PREFIX ) === 0 )
			.map( ( taxonomy ) => ( {
				code: taxonomy.slug.slice( PREFIX.length ),
				label: taxonomy.name,
				terms: ( getEntityRecords( 'taxonomy', taxonomy.slug, TERMS_QUERY ) || [] ).map( ( term ) => ( {
					slug: term.slug,
					name: term.name,
				} ) ),
			} ) );
	}, [] );
}
