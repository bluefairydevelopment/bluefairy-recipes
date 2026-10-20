/**
 * Recipe Filters block: the form alone, server-rendered.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from './block.json';
import '../editor.css';

function Edit( { attributes } ) {
	return (
		<div { ...useBlockProps() }>
			<ServerSideRender block={ metadata.name } attributes={ attributes } />
		</div>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
