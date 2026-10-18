<?php
/**
 * Plugin Name:       BlueFairy Recipes
 * Plugin URI:        https://wordpress.bluefairydevelopment.com/bluefairy-recipes/
 * Description:       Recipes for WordPress: ingredients, numbered steps, timings, servings, nutrition facts, photo or video, writer profiles, four browse taxonomies, a filterable recipe archive, Recipe JSON-LD for Google and a featured-recipes block. Add BlueFairy Recipes Pro for reviews, visitor submissions, WooCommerce shoppable ingredients and custom filter types.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Author:            Blue Fairy Development
 * Author URI:        https://bluefairydevelopment.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bluefairy-recipes
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BFRC_FILE', __FILE__ );
define( 'BFRC_DIR', plugin_dir_path( __FILE__ ) );
define( 'BFRC_URL', plugin_dir_url( __FILE__ ) );
define( 'BFRC_VERSION', '1.0.0' );
define( 'BFRC_DB_VERSION', '1.0.0' );

// Autoloader — no Composer required at runtime. Only this plugin's namespace;
// the Pro add-on loads its own classes.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'BlueFairy\\Recipes\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// Activation and deactivation hooks MUST be at file top level (not inside any hook).
register_activation_hook( __FILE__, [ BlueFairy\Recipes\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ BlueFairy\Recipes\Installer::class, 'deactivate' ] );

/**
 * Accessor the Pro add-on uses to confirm the base plugin is really loaded.
 *
 * BFRC_VERSION alone is not proof: a constant can be defined by anything.
 * Pro checks for this function AND the constant.
 */
function bfrc_plugin(): string {
	return BFRC_VERSION;
}

// Boot after all plugins have loaded. Plugin::boot() fires `bfrc_loaded`
// before it binds any subsystem, which is where the Pro add-on hooks in.
add_action( 'plugins_loaded', [ BlueFairy\Recipes\Plugin::class, 'boot' ], 10 );
