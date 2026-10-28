<?php
/**
 * Plugin Name:       BlueFairy Recipes
 * Plugin URI:        https://wordpress.bluefairydevelopment.com/bluefairy-recipes/
 * Description:       Recipes for WordPress: ingredients, steps, timings, nutrition, writer profiles, a filterable archive and Recipe rich results.
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

// A second copy of this plugin (a renamed folder, a zip uploaded twice) must
// not fatal on "cannot redeclare": the first copy loaded wins.
if ( defined( 'BFRC_VERSION' ) ) {
	return;
}

define( 'BFRC_FILE', __FILE__ );
define( 'BFRC_DIR', plugin_dir_path( __FILE__ ) );
define( 'BFRC_URL', plugin_dir_url( __FILE__ ) );
/**
 * The plugin's version.
 *
 * @api
 * @since 1.0.0
 */
define( 'BFRC_VERSION', '1.0.0' );
define( 'BFRC_DB_VERSION', '1.0.0' );

/**
 * The base's public PHP API version (docs/ARCHITECTURE.md, "Static API an
 * add-on may use"). Raised only by a change that breaks a listed symbol.
 *
 * @api
 * @since 1.0.0
 */
define( 'BFRC_API_VERSION', 1 );

// Autoloader — no Composer required at runtime. Only this plugin's namespace;
// the Pro add-on loads its own classes. The class name is validated before it
// becomes a path, so nothing outside includes/ can ever be loaded through it.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'BlueFairy\\Recipes\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+(?:\\\\[A-Za-z0-9_]+)*\z/', $relative ) ) {
			return;
		}
		$file = __DIR__ . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// Activation and deactivation hooks MUST be at file top level (not inside any hook).
register_activation_hook( __FILE__, [ BlueFairy\Recipes\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ BlueFairy\Recipes\Installer::class, 'deactivate' ] );

if ( ! function_exists( 'bfrc_plugin' ) ) {
	/**
	 * The loaded plugin's version, for the Pro add-on to confirm the base is
	 * really loaded. BFRC_VERSION alone is not proof: a constant can be
	 * defined by anything. Pro checks for this function AND the constant.
	 *
	 * @api
	 * @since 1.0.0
	 */
	function bfrc_plugin(): string {
		return BFRC_VERSION;
	}
}

// Boot after all plugins have loaded. Plugin::boot() fires `bfrc_loaded`
// before it binds any subsystem, which is where the Pro add-on hooks in.
add_action( 'plugins_loaded', [ BlueFairy\Recipes\Plugin::class, 'boot' ], 10 );
