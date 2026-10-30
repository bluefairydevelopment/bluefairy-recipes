/**
 * Featured Recipes block: title, mode and count in the inspector.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, SelectControl, RangeControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from './block.json';
import '../editor.css';

function Edit( { attributes, setAttributes } ) {
	const { mode, count } = attributes;
	// Unset (never edited) = the site language's "Featured recipes" (the server translates it); '' = no heading.
	const title = attributes.title === undefined ? __( 'Featured recipes', 'bluefairy-recipes' ) : attributes.title;
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Featured recipes', 'bluefairy-recipes' ) }>
					<TextControl
						label={ __( 'Heading', 'bluefairy-recipes' ) }
						help={ __( 'Leave empty for no heading.', 'bluefairy-recipes' ) }
						value={ title }
						onChange={ ( value ) => setAttributes( { title: value } ) }
					/>
					<SelectControl
						label={ __( 'Show', 'bluefairy-recipes' ) }
						value={ mode }
						options={ [
							{ value: 'featured', label: __( 'Featured recipes', 'bluefairy-recipes' ) },
							{ value: 'recent', label: __( 'Most recent recipes', 'bluefairy-recipes' ) },
						] }
						onChange={ ( value ) => setAttributes( { mode: value === 'recent' ? 'recent' : 'featured' } ) }
					/>
					<RangeControl
						label={ __( 'How many', 'bluefairy-recipes' ) }
						value={ count }
						min={ 1 }
						max={ 12 }
						onChange={ ( value ) => setAttributes( { count: Math.max( 1, Math.min( 12, Number( value ) || 1 ) ) } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<ServerSideRender
					block={ metadata.name }
					attributes={ attributes }
					EmptyResponsePlaceholder={ () => <p className="bfrc-block-placeholder">{ __( 'No recipes to show yet: mark some recipes as featured, or show the most recent ones.', 'bluefairy-recipes' ) }</p> }
				/>
			</div>
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
