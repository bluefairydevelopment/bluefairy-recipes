/**
 * Recipe Card block: pick one recipe by title.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ComboboxControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { decodeEntities } from '@wordpress/html-entities';
import { recipeLabel } from '../recipe-label';
import metadata from './block.json';
import '../editor.css';

function Edit( { attributes, setAttributes } ) {
	const { recipeId } = attributes;
	const [ search, setSearch ] = useState( '' );
	const options = useSelect( ( select ) => {
		const { getEntityRecords, getEntityRecord } = select( coreStore );
		const found = getEntityRecords( 'postType', 'bfrc_recipe', { search, per_page: 20, status: 'publish', orderby: 'title', order: 'asc' } ) || [];
		const list = found.map( ( recipe ) => ( { value: recipe.id, label: recipeLabel( recipe, decodeEntities ) } ) );
		if ( recipeId && ! list.some( ( option ) => option.value === recipeId ) ) {
			const current = getEntityRecord( 'postType', 'bfrc_recipe', recipeId );
			if ( current ) {
				list.unshift( { value: current.id, label: recipeLabel( current, decodeEntities ) } );
			}
		}
		return list;
	}, [ search, recipeId ] );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Recipe', 'bluefairy-recipes' ) }>
					<ComboboxControl
						label={ __( 'Recipe', 'bluefairy-recipes' ) }
						help={ __( 'Type to search published recipes by title.', 'bluefairy-recipes' ) }
						value={ recipeId || null }
						options={ options }
						onFilterValueChange={ setSearch }
						onChange={ ( value ) => setAttributes( { recipeId: Number( value ) || 0 } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				{ recipeId ? (
					<ServerSideRender
						block={ metadata.name }
						attributes={ attributes }
						EmptyResponsePlaceholder={ () => <p className="bfrc-block-placeholder">{ __( 'This recipe is not published.', 'bluefairy-recipes' ) }</p> }
					/>
				) : (
					<p className="bfrc-block-placeholder">{ __( 'Choose a recipe in the block settings.', 'bluefairy-recipes' ) }</p>
				) }
			</div>
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
