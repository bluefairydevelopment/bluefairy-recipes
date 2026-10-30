/**
 * Recipe List block: server-rendered, with per-page, fixed filters and the
 * two switches in the inspector.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __, sprintf } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl, ToggleControl, SelectControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from './block.json';
import { useRecipeTaxonomies } from '../taxonomies';
import '../editor.css';

function Edit( { attributes, setAttributes } ) {
	const { perPage, filters, showFilters, showPagination } = attributes;
	const taxonomies = useRecipeTaxonomies();
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'List', 'bluefairy-recipes' ) }>
					<RangeControl
						label={ __( 'Recipes per page', 'bluefairy-recipes' ) }
						help={ __( '0 uses the Recipes per page setting.', 'bluefairy-recipes' ) }
						value={ perPage }
						min={ 0 }
						max={ 100 }
						onChange={ ( value ) => setAttributes( { perPage: Number( value ) || 0 } ) }
					/>
					<ToggleControl
						label={ __( 'Show the filter form', 'bluefairy-recipes' ) }
						checked={ showFilters }
						onChange={ ( value ) => setAttributes( { showFilters: !! value } ) }
					/>
					<ToggleControl
						label={ __( 'Show pagination', 'bluefairy-recipes' ) }
						checked={ showPagination }
						onChange={ ( value ) => setAttributes( { showPagination: !! value } ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Fixed filters', 'bluefairy-recipes' ) } initialOpen={ false }>
					{ taxonomies.map( ( taxonomy ) => (
						<SelectControl
							key={ taxonomy.code }
							label={ taxonomy.label }
							value={ ( filters && filters[ taxonomy.code ] ) || '' }
							options={ [
								/* translators: %s: filter type plural name */
								{ value: '', label: sprintf( __( 'All %s', 'bluefairy-recipes' ), taxonomy.label ) },
								...taxonomy.terms.map( ( term ) => ( { value: term.slug, label: term.name } ) ),
							] }
							onChange={ ( value ) => {
								const next = { ...( filters || {} ) };
								if ( value ) {
									next[ taxonomy.code ] = value;
								} else {
									delete next[ taxonomy.code ];
								}
								setAttributes( { filters: next } );
							} }
						/>
					) ) }
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<ServerSideRender block={ metadata.name } attributes={ attributes } />
			</div>
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
