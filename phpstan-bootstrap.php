<?php
/**
 * Constants the entry file defines at runtime, declared for static analysis.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

define( 'BFRC_FILE', __DIR__ . '/bluefairy-recipes.php' );
define( 'BFRC_DIR', __DIR__ . '/' );
define( 'BFRC_URL', 'https://example.com/wp-content/plugins/bluefairy-recipes/' );
define( 'BFRC_VERSION', '1.0.0' );
define( 'BFRC_DB_VERSION', '1.0.0' );
define( 'WP_UNINSTALL_PLUGIN', true );

if ( ! function_exists( 'rest_get_route_for_user' ) ) {
	/**
	 * Core 5.5+ (missing from the stubs package in use).
	 *
	 * @param int|\WP_User $user The user.
	 */
	function rest_get_route_for_user( int|\WP_User $user ): string {
		return '';
	}
}
