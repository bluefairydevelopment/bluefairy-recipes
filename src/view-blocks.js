/**
 * The two template view blocks (bfrc/recipe-single, bfrc/recipe-archive)
 * registered by the server: their editor preview is a server-side render,
 * whose request carries `post_id` in the post editor so a block pasted into
 * a post's text shows a note there instead of another recipe's page.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import ServerSideRender from '@wordpress/server-side-render';

[ 'bfrc/recipe-single', 'bfrc/recipe-archive' ].forEach( ( name ) => {
	registerBlockType( name, {
		edit: ( { attributes } ) => (
			<div { ...useBlockProps() }>
				<ServerSideRender block={ name } attributes={ attributes } skipBlockSupportAttributes />
			</div>
		),
		save: () => null,
	} );
} );
