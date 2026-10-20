/**
 * @wordpress/scripts' default build (one script bundle per block.json
 * `editorScript`, one module per `viewScriptModule`) plus the view blocks'
 * editor script, which has no block.json of its own.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const [ scripts, modules ] = Array.isArray( defaultConfig ) ? defaultConfig : [ defaultConfig, null ];

const withViewBlocks = {
	...scripts,
	entry: () => ( {
		...( typeof scripts.entry === 'function' ? scripts.entry() : scripts.entry ),
		'view-blocks': './src/view-blocks.js',
	} ),
};

module.exports = modules ? [ withViewBlocks, modules ] : withViewBlocks;
